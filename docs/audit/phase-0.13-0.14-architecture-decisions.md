# Phases 0.13–0.14 — Architecture Decisions

**Generated:** 2026-09-26
**Covers:** legacy service migration (0.13) and architecture conformance verification (0.14).

---

## The governing principle

Every decision below follows one rule: **a refactoring phase may change where behaviour lives, never what the behaviour is.** Where a change would have altered financial output, authorization outcomes, transaction boundaries or lock ordering, the code was left alone and the problem was written into the debt register instead. This produced a phase that is *structurally correct and financially unchanged* — a smaller win than "fixed the bugs", but a safe one.

---

## ADR-0.13-01 — Extract booking orchestration into Application use cases, not Domain services

**Context.** `BookingController::storeCampaign` was 299 lines containing pricing validation, a `DB::transaction`, inventory locking, booking and screen writes, revenue distribution, event dispatch and HTTP response shaping.

**Options considered.**
1. Leave it — rejected, it is the single worst violation of Rule 1 in the codebase.
2. Move it to a Domain service — rejected, it is orchestration, not calculation, and it owns a transaction.
3. Move it to an Application use case — **chosen**.

**Decision.** Three classes were created: `CreateCampaignBookingUseCase`, `RescheduleBookingUseCase`, `CheckBookingAvailabilityQuery`, plus `BookingCreationException`.

**Consequence.** The controller is a thin adapter and the transaction has a named, testable owner. Booking domain *calculation* stays behind `CampaignPricingService` → `CampaignPricingEngine`, so Rule 2 holds.

---

## ADR-0.13-02 — Introduce `BookingCreationException` rather than returning HTTP responses from Application

**Context.** The four business rejections (no inventory, insufficient units, date window, availability conflict) previously returned `response()->json(..., 422)` from inside the transaction. Application code must not know about HTTP.

**Options considered.**
1. Return a result object and let the controller translate — rejected, it would require a 4-state union and loses the "abort the transaction" intent.
2. Throw a domain-specific exception carrying the response data — **chosen**.
3. Throw a generic `ValidationException` — rejected, Laravel's version cannot carry a custom `errors` payload, which would have broken the golden API contract.

**Decision.** `BookingCreationException` carries `httpStatus`, `message` and `errors`. The controller catches it and renders the identical response.

**Consequence.** The status/message contract now has exactly one source, so the two layers *cannot* drift. This is a structural improvement over the original, not merely a refactor.

**Critical detail.** Rejections now **throw** inside the transaction rather than returning early, so the transaction rolls back where it previously committed an empty transaction. Every rejection path occurs before the first write, so zero rows existed in either case and the observable result is identical. This was verified specifically.

---

## ADR-0.13-03 — Do **not** consolidate the single-screen booking path

**Context.** `POST /api/bookings` (single-screen) and `POST /api/bookings/campaign` both create bookings. It is tempting to route both through one use case.

**Decision. Declined, and the divergence is documented as intentional.**

**Reason.** The two paths are not two implementations of one behaviour. The legacy single-screen path **trusts the client-supplied price and writes no pricing snapshot**; the campaign path ignores the client price and always persists the engine's result. Unifying them would change financial behaviour for the legacy channel.

**Consequence.** `store` remains inline in the controller and is recorded in the use-case map as `retained - NOT consolidated`. This is the clearest example in the codebase of "same outcome, different contract, do not normalise".

---

## ADR-0.13-04 — Leave `markManualPayment` out of `CreateOfflinePaymentUseCase`

**Context.** An offline-payment use case already exists from Phase 0.9, and `BookingController::markManualPayment` performs a similar-looking operation.

**Decision. Declined.** The two paths diverge intentionally; the use case is documented as such rather than being presented as a near-duplicate to be cleaned up.

---

## ADR-0.13-05 — Extract `RolePermissionChecker` to `Support`, and do not add the super-admin bypass to it

**Context.** `App\Models\User::hasPermission()` resolved `App\Http\Middleware\PermissionMiddleware` statically — a model depending on the transport layer, and the only such inversion in `app/`. The permission algorithm was also duplicated in two places.

**Options considered.**
1. Move the algorithm into a Domain service — rejected, it is framework-level policy resolution, not a domain rule.
2. Make it a Laravel Policy — rejected, the application contains no `Gate::` call and no `authorize()` call anywhere, so a policy would be unreachable and untestable.
3. Put it in `app/Support/Authorization` and have both callers delegate — **chosen**.

**Decision.** `RolePermissionChecker` in `app/Support/Authorization`, injected into `PermissionMiddleware` and resolved by `User::hasPermission()`.

**Sub-decision: the super-admin asymmetry was preserved, not fixed.** The middleware bypasses the permission check for super-admins; the model helper does not. This is asymmetric and looks like a bug, but route-level authorization is the authoritative check, and the asymmetry is pinned by an existing test. "Fixing" it would change authorization behaviour, which this phase is not allowed to do. Recorded as low-severity debt.

**Consequence.** The only `App\Http` import in any model is gone, and the permission rule has one owner. The public static `PermissionMiddleware::clearPermissionCache()` was kept for framework compatibility, now delegating internally.

---

## ADR-0.13-06 — Reduce `CampaignPricingService` to a one-dependency compatibility wrapper rather than deleting it

**Context.** It injected 8 dependencies and used 1. Its 7 unused injections made it look like a God service.

**Options considered.**
1. Delete it and update all callers — rejected, it would break callers and golden tests for no architectural gain.
2. Keep the 8 injections — rejected, they are misleading.
3. Reduce to `PricingEngineInterface` and document it as a legacy wrapper — **chosen**.

**Consequence.** The class is now visibly transitional rather than deceptively central. The real pricing authority is unambiguously `CampaignPricingEngine`.

---

## ADR-0.13-07 — Validate dead code against the framework, not just against source references

**Context.** A reference-count scan was the primary dead-code method. It is insufficient: Laravel resolves console commands, scheduled tasks, event listeners, Eloquent relations, policies and providers by convention, not by import.

**Decision.** Every deletion candidate was cross-checked against `php artisan list`, `routes/console.php`, and the `Route::` table before removal.

**The decision that mattered most.** Three classes have **zero source references and are fully live**:

| Class | Signature | Schedule |
|---|---|---|
| `ExpireRewardPoints` | `rewards:expire` | daily |
| `ExpirePendingBookings` | `bookings:expire-pending` | every 5 minutes |
| `SyncExchangeRates` | `currency:sync-rates` | twice daily |

A purely lexical scan would have deleted three running scheduled jobs, including one that expires bookings every five minutes. All three were retained.

**Consequence.** 11 files were deleted (2 editor `.bak` files, 2 DTOs, 1 job, 1 unrouted controller, 4 undispatched events, 1 superseded service). 5 further classes were confirmed dead but **retained** — 2 policies (conventional extension point) and 3 pricing calculators (Rule 7 forbids removing a rule implementation until its canonical owner is designated).

---

## ADR-0.13-08 — Route the Application layer through legacy services rather than extracting them all

**Context.** 17 Application classes still depend on `App\Services`.

**Decision. Accepted as a transitional state.** Application → Services is the *correct* direction; Services → Application would be a violation. Rule 7 explicitly prescribes wrap-and-delegate first, extract deeper later.

**Consequence.** Recorded as low-severity progress-tracking, not a defect, with each service's own extraction as its work item.

---

## ADR-0.14-01 — Investigate the reported duplicate routes instead of trusting the report

**Context.** A Phase 0.13 subagent reported "14 methods registered twice in `routes/api.php`".

**Investigation.** `php artisan route:list` **cannot** detect this, because Laravel's `RouteCollection` is keyed by `method + domain + uri` — a later registration silently replaces an earlier one, so `route:list` shows 385 routes whether or not duplicates exist. Detection required resolving group prefixes and middleware from the source and comparing source-level registrations against the effective table.

**Findings.** The report was wrong in both number and framing:
- A naive line-level regex found 18 candidates; 3 were false positives (`dashboard`, `profile`, `campaigns` appear once under the `partner` prefix and once under `screen-provider`, so they are different URIs).
- The true count is **15**: 2 testimonial routes and 13 booking admin routes.
- Critically, in every collision the **later** registration was the *more restrictive* one, so the permission-gated route was always effective. **No admin route was ever reachable without its permission check.**

**Decision.** Remove the 15 dead declarations, and prove it is behaviour-neutral.

**Verification.** `route:list --json` before and after: 385 → 385 routes, identical `method + domain + uri` key set, and **0** routes whose action or middleware stack differed. A re-run of the shadowing analysis reports 0 remaining collisions.

**Why it still mattered.** Not an active vulnerability, but a **latent authorization hazard**: the first declaration a developer reads says `auth:sanctum` only, the opposite of the truth. Deleting the block that *looks* redundant, or editing the effective block, would have silently stripped the permission check from 13 admin routes including `approve`, `reject` and `manual-payment`.

---

## ADR-0.14-02 — Do not "fix" the target directory structure

**Context.** The brief's target tree lists 13 Domain modules and 9 Application modules. Reality is 3 and 5. 11 business domains are owned by the flat `app/Services` namespace (51 classes). `app/Helpers` holds exactly one class.

**Decision. Do not relocate.** The brief says not to force every class into the tree and to use actual responsibility to determine ownership.

**Reasoning.** A sweeping move of 51 services produces a large diff, breaks container bindings, and yields **zero** boundary benefit — the classes would sit in new directories with the same dependencies. A module earns its existence when its service is extracted. Each of the 11 unmapped domains is recorded individually in the directory conformance artifact so the gap is explicit rather than hidden.

**Consequence.** Reported as a genuine medium-severity gap, with the honest number (3/13 Domain, 5/9 Application) stated in the report rather than presented as conformance.

---

## ADR-0.14-03 — Do not relocate `CampaignPricingEngine`'s dependencies into Domain

**Context.** The engine is correctly placed in Domain but imports 7 `App\Services` classes and 3 Eloquent models, violating Rule 3.

**Tempting fix.** Move `PeakPricingCalculator`, `WeekendPricingCalculator` and the other stateless calculators into `app/Domain/Pricing/`. The class count would look right.

**Decision. Rejected.** Those calculators accept **Eloquent models as parameters**. Moving them into Domain would relocate the Active Record leak into the Domain layer rather than remove it — converting a visible Domain → Services violation into a subtler Domain → Model violation.

**Real fix (Phase 0.15).** Replace the Eloquent parameters with value objects, introduce a `PricingSettingsProvider` contract so the DB-backed setting becomes an injected dependency, and register an adapter in Infrastructure. That changes pricing determinism, so it needs its own approved phase.

**Consequence.** Recorded as medium-severity debt with the root cause explained, so a future phase does not attempt the cosmetic version of this fix.

---

## ADR-0.14-04 — Record implicit and missing transaction boundaries as debt rather than adding transactions

**Context.** The audit found 9 operations with an explicit transaction owner and 6 with an implicit or missing one.

**The most serious finding.** `Booking` model events reverse loyalty points and cancel or refund referral tracking. Because they are model events, they execute inside **whatever transaction the caller happened to open**. A caller updating booking status outside a transaction gets non-atomic financial side effects. This is the highest-severity architectural item found.

**Decision. Do not add transactions in this phase.** Adding one changes failure and lock behaviour on live endpoints; moving the model events changes *when* financial side effects commit. Both need dedicated regression coverage and explicit approval.

**Consequence.** Four high-severity debt items, each with the reason it was not fixed. The revenue-distribution-inside-booking-creation nesting was confirmed **intentional** and left alone.

---

## ADR-0.14-05 — Leave the two failing tests failing

**Context.** `BookingConcurrencyTest` and `CampaignBookingPricingIntegrityTest` assert the message `"no longer available"`, but the application returns the `EligibilityResult` message. Both failed before this phase and fail identically after.

**Decision. Do not touch.** The status code (422) and the error payload are correct; only the message string differs. Changing either the message or the assertion is a **user-visible string change**, i.e. a product decision, not a refactor.

**Verification value.** Their continued failure is itself evidence: it confirms the message layer was not silently altered during the extraction. If the extraction had changed a message, these tests would show a *different* failure.

---

## ADR-0.14-06 — Report the non-idempotent test accurately instead of suppressing its symptom

**Context.** The suite reported `mkdir(): File exists` from `tests/Unit/MediaHelperTest.php`, and a leftover `tests/compressed` directory was present in the working tree.

**First decision (wrong).** Delete the directory. The suite then reported `558 passed / 0 warnings`, and it was tempting to record the warning as resolved.

**Investigation.** `tests/Unit/MediaHelperTest.php:23` passes the **relative, fixed** path `'tests/compressed'` into `MediaHelper::compressUploadedImage()`, which calls `mkdir()` without an existence guard. The directory is recreated by each run. So deleting it suppresses the warning **for that run only** — confirmed by the next run reporting `557 passed / 1 warning` again.

**Second finding.** The same test's only assertion is
```php
$this->assertTrue($result['is_compressed'] || !$result['is_compressed']);
```
a **tautology** that can never fail. The test contributes no coverage, and its warning is a symptom of a test that is not meaningfully exercising the code.

**Decision.** Do not patch the test. Report the defect accurately: it is pre-existing, run-order dependent, and has no production impact. Changing the assertion would be a judgement call about intended coverage that belongs to whoever wrote the test.

**Consequence.** The final report states both numbers and explains the difference, rather than quoting the flattering one. Recorded as low-severity debt for a test-hygiene pass.

**Lesson recorded deliberately:** deleting a symptom and then reporting the symptom as fixed is a measurement error, not a fix. The clean run was mistaken for a resolved issue until the suite was re-run and the warning returned.

## ADR-0.14-07 — Report incomplete conformance honestly

**Context.** It would have been easy to present this phase as a clean sweep, since 7 of 9 architecture rules are now fully satisfied and every API, financial and transaction invariant is intact.

**Decision. State the gaps plainly.** 3/13 Domain modules, 5/9 Application modules, 9 `Storage::` calls in controllers, 7 service locators in models and services, 3 confirmed-dead classes retained, 4 high-severity debt items, and 2 permanently red tests.

**Reasoning.** A conformance report that rounds up is worse than no report, because the next phase would inherit a false picture of the codebase. Every deferred item names its root cause, its severity and the phase that should address it.
