# Phase 0.14 — API Contract Conformance

**Generated:** 2026-09-26
**Verdict:** ✅ **CONFORMANT** — the public API surface is byte-for-byte identical to the pre-existing baseline.

---

## 1. Route Table Comparison

The definitive check compares the live framework route table against `docs/audit/routes-baseline.json`, which was captured before any Phase 0.13/0.14 production change. Both the **action** and the **full sorted middleware stack** are compared for every route, so a change in authorization on any endpoint would be detected.

| Metric | Baseline | Current | Delta |
|---|---:|---:|---:|
| Total route entries | 385 | 385 | **0** |
| Distinct `method + domain + uri` keys | 385 | 385 | **0** |
| Routes **added** | — | **0** | — |
| Routes **removed** | — | **0** | — |
| Routes with a changed **action** | — | **0** | — |
| Routes with a changed **middleware stack** | — | **0** | — |

```
ADDED keys: 0
REMOVED keys: 0
CHANGED action/middleware on existing keys: 0
```

**No endpoint was added, removed, renamed, re-pointed, or had its middleware altered during Phases 0.13–0.14.**

---

## 2. Why This Matters More Than Usual Here

Phase 0.14 removed 15 route declarations from `routes/api.php` (see §4). A route-table comparison is therefore also the proof that those 15 lines were genuinely unreachable. If any of them had been live, the removed/added counts above would be non-zero. They are zero, which independently confirms the shadowing analysis.

---

## 3. Response Envelope and Status Contract

The campaign-booking path was the most heavily refactored surface: `BookingController::storeCampaign` moved into `CreateCampaignBookingUseCase`. Its HTTP contract is preserved by construction, because the controller now catches `BookingCreationException` and reproduces the exact response it used to build inline.

| Contract element | Before | After | Verified by |
|---|---|---|---|
| Success envelope | `{"success": true, "data": {...}}` | identical | `GoldenApiContractRegressionTest` (8 cases) |
| Rejection envelope | `{"success": false, "message": ..., "errors": {...}}` | identical | `GoldenApiContractRegressionTest`, `GoldenBookingRegressionTest` (4 cases) |
| Rejection HTTP status | 422 | 422 | `GoldenApiContractRegressionTest` |
| Rejection message text | `EligibilityResult` message | identical | `GoldenPricingRegressionTest` (7 cases) |
| `errors` payload keys and values | per-reason map | identical | `GoldenApiContractRegressionTest` |
| Pricing source of truth | engine result, never client input | identical | `GoldenPricingRegressionTest` |
| `errors` key for the `BookingCreationException` path | per-reason map | identical | `GoldenBookingRegressionTest` |

**Mechanism that guarantees this:** the exception carries `httpStatus`, `message` and `errors` as data. The controller builds the identical response from them. There is no second place where a status code or message is decided, so the two cannot drift.

---

## 4. Route Shadowing — Investigated and Resolved

A Phase 0.13 subagent reported "14 methods registered twice". Phase 0.14 investigated this properly and found the report was wrong in both number and framing.

### 4.1 Detection method

`php artisan route:list` **cannot** detect duplicate registrations, because Laravel's `RouteCollection` is keyed by `method + domain + uri` and the later registration silently replaces the earlier one. `route:list` therefore shows 385 routes whether or not duplicates exist in the source. The check was done by resolving group prefixes and middleware from the source and comparing source-level registrations against the effective table.

A naive line-level regex produced 18 candidates; 3 were false positives (`dashboard`, `profile`, `campaigns` appear once under the `partner` prefix and once under `screen-provider`, so they are different URIs). The true count is **15**.

### 4.2 The 15 shadowed registrations

| Route | Dead declaration | Effective declaration |
|---|---|---|
| `GET testimonials/{testimonial}` | line 203, `auth:sanctum` only | `auth:sanctum, permission:testimonials.view` |
| `PUT testimonials/{testimonial}` | line 204, `auth:sanctum` only | `auth:sanctum, permission:testimonials.view, permission:testimonials.edit` |
| `POST /bookings/{booking}/approve` | line 215, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.approve` |
| `POST /bookings/{booking}/reject` | line 216, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.approve` |
| `GET /bookings/{booking}/review` | line 217, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.approve` |
| `POST /bookings/{booking}/mark-media-review` | line 218, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.approve` |
| `POST /bookings/{booking}/media/{media}/approve` | line 219, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.approve` |
| `POST /bookings/{booking}/media/{media}/reject` | line 220, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.approve` |
| `POST /bookings/{booking}/start-session` | line 221, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.edit` |
| `POST /bookings/{booking}/end-session` | line 222, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.edit` |
| `POST /bookings/{booking}/extend-session` | line 223, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.edit` |
| `POST /bookings/{booking}/manual-payment` | line 224, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.edit` |
| `POST /bookings/{booking}/resend-confirmation` | line 225, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.edit` |
| `POST /bookings/{booking}/notes` | line 226, `auth:sanctum` only | `auth:sanctum, permission:bookings.view, permission:bookings.edit` |
| `GET /bookings/{booking}/timeline` | line 227, `auth:sanctum` only | `auth:sanctum, permission:bookings.view` |

### 4.3 Was this a vulnerability?

**No — the effective behaviour was and remains the permission-gated route.** In every one of the 15 collisions, the *later* registration was the more restrictive one, and later wins. No admin route (including `approve`, `reject` and `manual-payment`) was ever reachable without its permission check.

It was, however, a **latent authorization hazard**: the first declaration a developer reads says `auth:sanctum` only, which is the opposite of the truth. Deleting the block that *looks* redundant, or editing the effective block, would have silently stripped the permission check from 13 admin routes.

### 4.4 Resolution

The 15 dead declarations were removed. Verified behaviour-neutral:

- `route:list --json`: **385 before, 385 after**
- Identical set of `method + domain + uri` keys
- **0** routes whose action or middleware stack differed
- Re-run of the shadowing analysis: **0** remaining colliding registrations

---

## 5. Endpoint Groups Verified Unchanged

| Group | Endpoints | Verification |
|---|---:|---|
| Auth (public) | 12 | Route table identical; login/register/logout untested for content but structurally unchanged |
| Public catalogue (assets, screens, blogs, jobs, FAQs, testimonials index) | 41 | Route table identical |
| Public pricing & availability | 4 (`/campaigns/calculate`, `/campaigns/validate`, `/recommendations/calculate`, `/pricing-plans/preview`) | Route table identical; `GoldenPricingRegressionTest` passes |
| Customer bookings | 20 | `GoldenBookingRegressionTest`, `GoldenAvailabilityRegressionTest` pass |
| Admin booking workflow | 13 | Route table identical incl. middleware; see §4 |
| Payments | 6 | `GoldenPaymentBoundaryTest` (12 cases) passes |
| Financial / reports | 31 | `GoldenFinancialBoundaryTest` (15 cases) passes |
| Partner & screen-provider portals | 24 | Route table identical (incl. the two `prefix` groups that caused the false positives) |
| v2 engine endpoints | 10 | Route table identical |
| Public files (gallery, contact, enquiries, currencies) | 12 | Route table identical |
| Console commands | 3 | `php artisan list` confirms all three still registered |

---

## 6. Known Contract Deviations (Pre-existing, Not Introduced Here)

| Deviation | Status |
|---|---|
| `BookingConcurrencyTest` and `CampaignBookingPricingIntegrityTest` assert `"no longer available"` but the app returns the `EligibilityResult` message (`"Asset 'X' has only N units available, but M requested."`) | **Pre-existing, unchanged.** Status code (422) and payload shape are correct; only the message string differs. Fixing it is a user-visible string change and therefore a product decision. |
| `deleteMedia` has no ownership check while `uploadMedia` does | **Pre-existing, unchanged.** Fixing it would change authorization behaviour. |
| `User::hasPermission()` has no super-admin bypass while `PermissionMiddleware` does | **Pre-existing, deliberately preserved** in Phase 0.13 and pinned by a test. |
| `Bookings` admin routes declared twice | **Resolved in Phase 0.14** — see §4. |

---

## 7. Conclusion

The external API contract of Phases 0.13–0.14 is **unchanged in every measurable dimension**: no route added, removed, renamed, re-pointed or re-authorized, and no response envelope, status code or error payload altered. The 15 route declarations that were removed were provably unreachable, and their removal is the strongest available evidence that they were dead rather than in use.
