# Phases 0.17–0.18 — Final Report

- **Date:** 2026-09-26
- **Project:** `illuvision/core-backend`
- **Scope:** legacy code and controller retirement (0.17) + modular monolith migration (0.18)
- **Artifacts:** 9 (Phase 0.17) + 12 (Phase 0.18) + 8 (combined) = **29**, all written and JSON-validated

---

## Exit decision

# `PHASE 1 BLOCKED`

Phases 0.17 and 0.18 completed their own scope successfully and provably changed no
behaviour. They cannot, however, clear Phase 0, because the four **P0 findings raised in
Phase 0.16 remain open and unmitigated**, and two of them are live authorization
failures on financial write paths.

This decision is not a judgement on the work in this report. It is the honest
consequence of a rule the brief set in advance: *do not proceed to Phase 1 while a P0
security or deployment blocker is open.*

---

## What was done

### Phase 0.17 — retirement

16 dead production classes (324 lines) were removed after full verification across
every runtime registration surface: route table, scheduler, Artisan registry, container
bindings, Gate policy resolution, Eloquent conventions, configuration, database-stored
class names, events, jobs, tests and views.

Removed: 9 duplicate/unused enums, 2 unused exceptions, 2 phantom policies, 3 unused
pricing calculators. The empty `app/Core` scaffolding (7 directories, 0 files) was
removed as well.

**Six false positives were caught and prevented from becoming data loss:**

| Class | Initially flagged | Reality |
|---|---|---|
| `ExpirePendingBookings` | DEAD | **LIVE** scheduled job `bookings:expire-pending` |
| `ExpireRewardPoints` | DEAD | **LIVE** scheduled job `rewards:expire` |
| `SyncExchangeRates` | DEAD | **LIVE** scheduled job `currency:sync-rates` |
| `PaymentStatusProjector` | DEAD | ACTIVE — used by `GetPaymentStatusUseCase` |
| `AssetDiscoveryFilterService` | DEAD | ACTIVE — used by `AssetService` |
| `AssetPolicy` | **ACTIVE** | **DEAD** — Gate convention resolved it for the non-existent `App\Models\Asset` |

The last row is the most valuable: a *reverse* false positive, where a dead policy
looked live. It corrects the Phase 0.13 decision to retain both policies.

### Phase 0.18 — migration

3 classes were relocated into their correct homes, establishing the first genuinely
owned module and the first `Support/Events` home:

- `App\Listeners\NotificationEventSubscriber` → `App\Support\Events\…` (subscribes to 18 events across 6 domains — genuinely cross-cutting)
- `App\Services\AssetDiscoveryFilterService` → `App\Application\Asset\Services\…`
- `App\Services\CoverageService` → `App\Application\Asset\Services\…`

Each depends only on contracts — no Eloquent, no `Domain` back-reference, no `Http`, no
service locator. That property is what made the moves provably safe.

**Two layers were found to need no migration at all.** A sweep of all 48 services found
**zero** external HTTP calls: every integration already sits behind a contract in
`app/Infrastructure` (Auth, ExchangeRates, Media, Notification, Payments, Financial).
All 81 controllers are already correctly layered.

---

## Metrics: before → after

| Metric | Before | After | Δ |
|---|---|---|---|
| Production PHP classes | 352 | 336 | **−16** |
| Services classes | 51 | 46 | −5 |
| Enums | 12 | 3 | −9 |
| Policies | 2 | 0 | −2 |
| Exceptions | 6 | 4 | −2 |
| Application classes | 32 | 34 | +2 |
| Support classes | 9 | 10 | +1 |
| **Routes** | **385** | **385** | **0** |
| **Tables** | **74** | **74** | **0** |
| **Columns** | **796** | **796** | **0** |
| **Indexes** | **252** | **252** | **0** |
| **Foreign keys** | **94** | **94** | **0** |
| **Migrations applied / pending** | 112 / 0 | 112 / 0 | 0 |
| **Container bindings** | 35 | 35 | 0 |
| **Tests passing** | 571 | 571 | 0 |
| **Assertions** | 5,323 | 5,323 | 0 |

---

## Verification evidence

| Check | Result |
|---|---|
| Full test suite | 571 passed, 2 failed, 1 warning, 4 skipped — **identical to baseline** |
| Route parity (method + URI + action + middleware) | **0 differences**, checked 4 times |
| Schema parity | **IDENTICAL** via `information_schema` |
| Architecture test | 14 passed, 17 assertions |
| Stale references to moved/deleted classes | **0** |
| Container resolution of every consumer | **OK** (after one real break was found and fixed) |
| Illegal dependencies introduced | **0** |
| Cycles introduced | **0** |

The 2 failures and 1 warning are pre-existing and unrelated: two message-wording
assertions and one tautological assertion in `MediaHelperTest`. They were deliberately
**not** "fixed", because editing an assertion string during a retirement phase would
mask a genuine behaviour question.

---

## A real defect caught during migration

Moving `CoverageService` and `AssetDiscoveryFilterService` out of `App\Services`
silently broke `App\Services\AssetService`:

```
BindingResolutionException: Target class [App\Services\CoverageService] does not exist.
```

`AssetService` type-hinted both classes **from its own namespace**, so no import
statement existed to rewrite. Both a stale-FQCN grep *and* resolving each migrated class
in isolation had already passed — only resolving the **consumer** through the container
exposed it.

Had the verification stopped at "grep is clean" or "the moved class resolves", this would
have shipped as a runtime 500 on asset discovery and coverage endpoints. The two missing
`use` statements were added, and container-resolution-of-consumers is now a mandatory
migration gate (ADR-18-04).

---

## New finding: an unenforced architecture rule

`Domain → Models` is **not** enforced by `ArchitectureRuleTest`, although the target
architecture states Domain must not import Models. `App\Models` is missing from the
`Domain` forbidden-import list, and **5 Domain files do import it**:

`SettlementRepositoryInterface`, `PartnerCommissionRepositoryInterface`,
`PaymentRepositoryInterface`, `PaymentStatus`, `CampaignPricingEngine`.

This debt was **not** captured in the Phase 0.15 debt register — it is new information.

The test was not weakened and no rule was removed. The recommended fix is to add the
check with an explicit 5-class allowlist so the debt becomes visible and cannot grow,
then eliminate it class by class via DTO-returning repository contracts.

---

## Why Phase 0.18 is explicitly partial

Only 2 of 26 probed target directories now exist. This is reported as **partial**, not
dressed up.

- **18 of the 45** remaining services are explicit brief hard stops: pricing,
  availability, booking, payment, refund, settlement, commission, revenue split.
- The rest are **Eloquent-shaped**. Moving them into `Application/` before extracting a
  persistence port would place `Models` inside `Application` and make ownership *less*
  clear — which Rule 26 explicitly forbids. The correct order is **extract port → then
  move**.
- The availability trio must stay split by design: `BookingCapacityService` is a
  non-locking *check*; `InventoryService` holds the `lockForUpdate` reservation inside
  the booking transaction. Merging them would move lock semantics.
- No empty directories were created to inflate conformance. Section 3 of the brief
  forbids it, so the empty `app/Core` scaffolding was deleted instead.

The remaining 45 services are recorded in `phase-0.18-application-module-map.json` as a
**sequenced plan with a verification gate per move**, not as an implicit backlog.

---

## Blockers preventing `PHASE 1 READY`

### P0 — must be fixed

| ID | Finding | Impact |
|---|---|---|
| **SEC-01** | `PATCH /api/bookings/{booking}` bypasses `permission:bookings.edit` | Runtime proof: HTTP 200, `status=approved` and `price=1.00` persisted for a user without edit permission. Unauthorised write on a financial path. |
| **SEC-02** | 19 `/api/v2/*` routes are fully anonymous | Includes finance **reads** and settlement **writes**. Unauthenticated financial data exposure and state change. |
| **SEC-03** | `/api/roles*` and `/api/partners*` authenticated but unauthorised | Role-permission updates permit privilege escalation. |
| **DEP-01** | `php artisan about` reports `Debug Mode: ENABLED` | Stack traces and environment disclosure in the current environment; must be provably `false` in production. |

None was introduced by Phases 0.17–0.18. All four were verified identical to
`docs/audit/routes-baseline.json`. None was silently fixed, because each fix changes an
authorization or deployment contract and therefore requires explicit sign-off.

### P0 — production deployment readiness

There is no version control, no CI pipeline, no backup or restore procedure, and no
health-check endpoint in this working tree. Deploying or rolling back is not currently
possible.

### Additional controlled debt (non-blocking, recorded)

- Rule 2: 7 pinned `Domain → Services` imports, all in `CampaignPricingEngine`
- Rule 12: 8 classes with pinned service locators (2 in `Http`)
- `Domain → Models`: 5 files, unenforced (new finding above)
- Gallery and admin asset-import routes reachable without permission
- Uploads stored under a Web-accessible root
- Booking idempotency key not implemented
- `.env.testing` holds real-looking credentials
- No Sentry; no structured logging (`LOG_LEVEL=debug`); Laravel's `inspire` demo command still scheduled

---

## What Phase 1 requires

1. Remediate SEC-01, SEC-02, SEC-03 and DEP-01, then re-run the route baseline to prove
   the change is intentional.
2. Establish version control, CI, backups and a health check.
3. Execute the sequenced 45-service migration, respecting the per-move verification
   gates and the extract-port-first rule for Eloquent-shaped services.
4. Add the missing `Domain → Models` check with an explicit allowlist.
5. Fix the two pre-existing message-wording assertions deliberately, deciding whether
   the code or the assertion is correct.
6. Remove the Laravel `inspire` demo scheduler entry.

Until items 1 and 2 are closed, the correct status is **`PHASE 1 BLOCKED`**.
