# Phase 0.13 — Legacy Architecture Migration: Architecture Diff

**Generated:** 2026-09-26
**Scope:** Extract the highest-risk legacy booking and pricing orchestration out of HTTP, thin the authorization seam, and remove code that can be proven unreachable.

---

## 1. Before / After Summary

| Dimension | Before Phase 0.13 | After Phase 0.13 | Delta |
|---|---|---|---|
| `BookingController.php` lines | 1827 | 1414 | **−413 (−22.6%)** |
| `storeCampaign` lines (largest method) | 299 | 50 | **−249 (−83%)** |
| Controllers | 69 | 68 | −1 (`CampaignPricingController` was dead) |
| Legacy services in `app/Services` | 52 | 51 | −1 (`NotificationDispatcher` was dead) |
| Application-layer classes | 28 | 32 | **+4** (3 new use cases + 1 exception) |
| Application use cases / queries | 9 | 12 | **+3** |
| Models reaching into `App\Http` | 1 (`User`) | **0** | **−1 (resolved)** |
| `app()` service locators in `BookingController` | 2 | 0 | **−2** |
| `app()` service locators in `AdminPaymentController` | 2 | 0 | **−2** |
| `app()` service locators in `PartnerBookingController` | 2 | 0 | **−2** |
| `CampaignPricingService` constructor dependencies | 8 | 1 | **−7** |
| Confirmed-dead files removed | — | 11 | **−11** |

---

## 2. Dependency Direction Diff

### 2.1 Before

```
HTTP ──> BookingController (1827 lines)
           │  owns: pricing validation, DB::transaction,
           │        inventory locking, booking write, screens write,
           │        revenue distribution, event dispatch, HTTP shaping
           │
           ├── app(LoyaltyService)          ← service locator
           ├── app(RevenueSplitResolverService) ← service locator
           ├──> CampaignPricingService (8 injected deps, 7 unused)
           ├──> InventoryService
           └──> BookingCapacityService

App\Models\User ──> App\Http\Middleware\PermissionMiddleware  ← INVERTED
                     (a model reaching into the transport layer)

App\Services\CampaignPricingService ──> (7 injected deps, never used)
```

### 2.2 After

```
HTTP ──> BookingController (1414 lines)  ── thin adapter
           │
           ├──> CreateCampaignBookingUseCase   [NEW]
           │        │  owns DB::transaction + inventory lock ordering
           │        ├──> InventoryService
           │        ├──> BookingCapacityService
           │        ├──> CampaignPricingService ──> PricingEngineInterface
           │        ├──> DistributeBookingRevenueUseCase
           │        └──> BookingCreationException [NEW]
           │
           ├──> RescheduleBookingUseCase       [NEW]
           │        └── owns DB::transaction + merged lock set
           │
           └──> CheckBookingAvailabilityQuery  [NEW]
                    └── read-only

App\Support\Authorization\RolePermissionChecker  [NEW]
        ▲                    ▲
        │                    │
PermissionMiddleware      App\Models\User
  (thin HTTP adapter)      (no HTTP dependency)

App\Services\CampaignPricingService ──> PricingEngineInterface (1 dep)
```

**Net effect:** the dependency arrow between the persistence model and the HTTP layer is **gone**, and booking orchestration now points *into* the Application layer instead of being trapped in the controller.

---

## 3. Classes Added

| Class | Layer | Lines | Responsibility |
|---|---|---|---|
| `app/Application/Booking/UseCases/CreateCampaignBookingUseCase.php` | Application | 314 | Campaign booking creation: pricing validation, inventory lock, booking + screen + revenue writes, event dispatch |
| `app/Application/Booking/UseCases/RescheduleBookingUseCase.php` | Application | 130 | Reschedule with merged old+new screen lock set |
| `app/Application/Booking/Queries/CheckBookingAvailabilityQuery.php` | Application | 60 | Read-only conflict check |
| `app/Application/Booking/Exceptions/BookingCreationException.php` | Application | 34 | Carries HTTP status, message and error payload out of the Application layer |
| `app/Support/Authorization/RolePermissionChecker.php` | Support | 84 | The single owner of the role→permission decision |

## 4. Classes Removed (11 confirmed dead)

| File | Evidence |
|---|---|
| `app/Http/Controllers/BookingController.php.bak` | Editor backup, `routes/api.php.bak` identical content, never autoloaded |
| `routes/api.php.bak` | Editor backup, never loaded by the framework |
| `app/DTOs/AssetDTO.php` | 0 references; not a Model, so no relation discovery |
| `app/DTOs/CreateBookingDTO.php` | 0 references |
| `app/Services/NotificationDispatcher.php` | 0 references; superseded by `NotificationService` behind `MailContractInterface` |
| `app/Jobs/GenerateAssetCodeJob.php` | 0 references; never dispatched, not in `routes/console.php` |
| `app/Http/Controllers/Api/CampaignPricingController.php` | 0 references; `/campaigns/calculate` and `/campaigns/validate` are routed to `BookingController` |
| `app/Events/Notifications/ClientCreated.php` | 0 dispatch sites, 0 listener registrations |
| `app/Events/Notifications/CampaignActivated.php` | 0 dispatch sites, 0 listener registrations |
| `app/Events/Notifications/CampaignCompleted.php` | 0 dispatch sites, 0 listener registrations |
| `app/Events/Notifications/SettlementReady.php` | 0 dispatch sites, 0 listener registrations |

`app/DTOs/` and `app/Jobs/` became empty and were removed as a direct consequence of the deletions above.

### 4.1 Confirmed dead but deliberately retained (5)

| Class | Why retained |
|---|---|
| `app/Policies/AssetPolicy.php` | Conventional authorization extension point. The app has no `Gate::` or `authorize()` call, so it is currently unreachable, but it is the correct home for future policy work. |
| `app/Policies/PartnerBookingPolicy.php` | Same rationale. |
| `app/Services/Pricing/PeakPricingCalculator.php` | Superseded duplicate — peak adjustment is computed inline in `CampaignPricingEngine`. Rule 7 forbids removing a rule implementation until its canonical owner is designated. |
| `app/Services/Pricing/WeekendPricingCalculator.php` | Same rationale. |
| `app/Services/Pricing/CampaignDiscountCalculator.php` | Same rationale. |

### 4.2 The dead-code scan's most important negative result

Three classes have **zero source-code references and are still live**:

| Class | Registration |
|---|---|
| `App\Console\Commands\ExpireRewardPoints` | `rewards:expire`, scheduled daily in `routes/console.php` |
| `App\Console\Commands\ExpirePendingBookings` | `bookings:expire-pending`, scheduled every 5 minutes |
| `App\Console\Commands\SyncExchangeRates` | `currency:sync-rates`, scheduled twice daily |

A purely lexical dead-code scan would have deleted three running scheduled jobs. Every candidate was therefore validated against `php artisan list` before deletion.

---

## 5. Behaviour Preservation Evidence

The extraction had to be provably behaviour-neutral. Each of these was checked:

| Concern | How it was preserved | Evidence |
|---|---|---|
| Transaction boundary | `DB::transaction` moved with the method, unchanged scope | 48 `DB::transaction` sites before and after |
| Lock ordering | Inventory `lockForUpdate` still taken **before** any availability/capacity read | 8 `lockForUpdate` sites before and after; `BookingConcurrencyTest` passes |
| Authoritative pricing | Client-supplied price is still ignored on the campaign path; the pricing engine result is still what is persisted | `CampaignBookingPricingIntegrityTest` passes; `GoldenPricingTest` passes |
| Revenue distribution | Still executed **inside** the same transaction, still after the booking row exists | `GoldenFinancialRegressionTest` passes |
| Event timing | `BookingCreated` still dispatched at the **end** of the transaction, not after commit | Pre-existing semantics preserved deliberately |
| Error contract | Rejections now raise `BookingCreationException` carrying status 422 + the original message + the original `errors` payload | `GoldenApiContractTest` and `GoldenBookingTest` pass |
| Response envelope | Unchanged `success`/`data`/`message` shape | 385 routes identical |
| Authorization | `RolePermissionChecker` reproduces the existing algorithm, cache key and cache TTL exactly; super-admin bypass remains middleware-only | 63 reporting tests / 363 assertions pass |

**Because every business rejection now throws inside the transaction rather than returning early, the transaction is rolled back in both cases — and in each rejection path zero rows had been written, so the observable result is identical to the previous early return.** This was the single most delicate part of the extraction and is called out explicitly.

---

## 6. Architecture Rules Effect

| Rule | Before | After |
|---|---|---|
| Rule 11 — Strict layer dependency direction | **Violated** by `BookingController::storeCampaign`, which held pricing validation and revenue logic in the transport layer | **Compliant** for campaign booking, reschedule and conflict check |
| Rule 11 (continued) — Application orchestrates, Domain calculates | Partial | Improved — orchestration now has a named owner |
| Rule 2 — Pure domain invariants (in-memory state or value objects only) | **Violated** by `CampaignPricingEngine` (7 service + 3 model imports) | **Still violated** — deferred to Phase 0.15, see debt register |
| Rule 4 — External network operations isolated outside row locks | Compliant | Compliant |
| Rule 3 — Vendor encapsulation in Infrastructure | Compliant | Compliant |
| Rule 6 — High-value repository abstraction only | Compliant | Compliant |
| Rule 13 — Verified deletion | N/A | Applied — 11 deletions, each with reference-count evidence **and** framework-registration verification |
| Rule 12 — Authorization at the route boundary | Compliant | Improved — the permission decision has one owner instead of two divergent copies |
| Rule 11 (continued) — no service locator in Domain/Application | Compliant (no locator in Domain/Application) | Compliant — 6 controller locators removed; the 7 remaining live locators are in `app/Models` and `app/Services` and are recorded as debt |

---

## 7. What Phase 0.13 Deliberately Did **Not** Do

These were in scope by proximity but were **not** changed, because changing them would alter financial, authorization or transactional behaviour — which the phase brief forbids doing as a side effect of a refactor:

1. **`Booking` model events** that reverse loyalty points and cancel/refund referrals via `app()`. The side effects are correct and covered by tests; extracting them changes *when* they commit. Highest-priority Phase 0.15 item.
2. **Single-screen `store`** (`POST /api/bookings`) was not merged with `CreateCampaignBookingUseCase`. The legacy channel trusts the client-supplied price and writes no pricing snapshot, so unifying the two would change financial behaviour. Recorded as a deliberate divergence.
3. **`markManualPayment`** was not merged into `CreateOfflinePaymentUseCase`. The two paths diverge intentionally.
4. **Missing transactions** on approve/reject/media-review/session/manual-payment were not added.
5. **`CampaignPricingEngine`'s** legacy-service dependencies were not relocated. Moving the calculators into Domain would move the Eloquent leak rather than remove it; the real fix is value-object parameters, which is a pricing-determinism change.
6. **`deleteMedia`'s missing ownership check** was not fixed — that would change authorization behaviour and needs a security decision.
7. **The 9 `Storage::` call sites** in 6 controllers were left in place by explicit Phase 0.12 user decision.

---

## 8. Residual Risk After Phase 0.13

The extraction moved orchestration into a named place. It did **not** reduce the number of things that can go wrong financially. The three highest-severity items are unchanged and now formally recorded in `phase-0.14-architecture-debt-register.json`:

1. Implicit transaction ownership for loyalty/referral side effects in `Booking` model events (high).
2. Reschedule does not recompute pricing or revenue distribution, so stored financial snapshots can go stale (high).
3. No transaction around approve/reject/media-review/session/manual-payment (high).

Phase 0.13 is therefore best described as **structurally correct, financially unchanged**.
