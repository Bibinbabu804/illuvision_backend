# Phase 0.10 — Financial Architecture Diff

**Generated:** 2026-09-26
**Scope:** Financial boundary extraction, behaviour-preserving.
**Database migrations:** none.
**Public API changes:** none.

---

## 1. What this phase is

Phase 0.10 puts the revenue, settlement and commission subsystems behind explicit boundaries
without changing a single stored value, transaction boundary, or externally observable behaviour.

The Financial subsystem is the highest-risk area in the codebase — it moves real money and
reconciles against historical snapshots. The guiding constraint was therefore: **extract, never
recompute, never "improve" the arithmetic.**

---

## 2. Files created (8)

### Domain

| File | Purpose |
|---|---|
| `app/Domain/Financial/Support/RevenueAllocation.php` | Pure per-screen gross, share split, and Illuvision-owned allocation. No framework imports. |
| `app/Domain/Financial/Contracts/SettlementRepositoryInterface.php` | Persistence contract for settlement eligibility. |
| `app/Domain/Financial/Contracts/PartnerCommissionRepositoryInterface.php` | Persistence contract for commissions, including the locked read. |

### Infrastructure

| File | Purpose |
|---|---|
| `app/Infrastructure/Financial/Persistence/EloquentSettlementRepository.php` | The eligibility aggregate read, reproduced byte-for-byte including both branches. |
| `app/Infrastructure/Financial/Persistence/EloquentPartnerCommissionRepository.php` | Commission reads/writes; `findOrFailForUpdate()` preserves `lockForUpdate()`. |

### Application

| File | Purpose |
|---|---|
| `app/Application/Financial/UseCases/DistributeBookingRevenueUseCase.php` | Revenue distribution entry point. |
| `app/Application/Financial/UseCases/GenerateProviderSettlementUseCase.php` | Settlement generation entry point. |
| `app/Application/Financial/UseCases/RecordReferralAttributionUseCase.php` | Referral attribution entry point. |

---

## 3. Files modified (8)

### `app/Services/RevenueDistributionService.php`

**Before** — inline in `processBookingScreens()`:
```php
$grossRevenue = round($booking->final_amount / $totalScreensInBookings, 2);
$platformShare = round($grossRevenue * ($illuvisionPct / 100), 2);
$providerShare = round($grossRevenue * ($providerPct / 100), 2);
```

**After**
```php
$grossRevenue = RevenueAllocation::grossPerScreen(
    $booking->final_amount,
    $booking->subtotal ?? $booking->price ?? 0,
    $totalScreensInBookings
);

$allocation = $illuvisionOwned
    ? RevenueAllocation::illuvisionOwned($grossRevenue)
    : RevenueAllocation::split($grossRevenue, $illuvisionPct, $providerPct);
```

**Critically preserved:** each share is computed from the *already-rounded* gross, and the two shares
round *independently*. This means `platform_share + provider_share` can differ from `gross_revenue`
by up to 0.01. That drift is a property of the existing ledger. It was **not** "fixed" — correcting
it would retroactively change every settled historical amount. See ADR-P10-03.

### `app/Services/SettlementService.php`

- Injects `SettlementRepositoryInterface`; the eligibility query moves out.
- **Not changed:** the `DB::transaction`, the summation, the reference-number format, the activity
  log, the `fresh()` reload, and all four lifecycle methods.

### `app/Services/CommissionService.php`

- Injects `PartnerCommissionRepositoryInterface`; three `lockForUpdate()` reads move behind
  `findOrFailForUpdate()`.
- **Not changed:** the transition guards, the status values, or the lock strength.

### `app/Services/ReferralTrackingService.php`

- Injects `PartnerCommissionRepositoryInterface` and `DistributeBookingRevenueUseCase`.
- **Not changed:** the duplicate-guard semantics, the backfill, or the caller-owned transaction
  requirement.

### `app/Services/ManualBookingService.php`

- Injects the two use cases; revenue distribution and referral attribution go through them.
- Removes two `app(ReferralTrackingService::class)` service locators.
- **Not changed:** the offline payment transaction, the `PaymentReceived` dispatch, or the
  conditional booking status move.

### `app/Http/Controllers/BookingController.php`

- Injects `DistributeBookingRevenueUseCase`; `storeCampaign()` calls it instead of the service.
- Drops the now-unused `RevenueDistributionService` injection.
- **Not changed:** validation, the enclosing transaction, event dispatch, or response shape.

### `app/Http/Controllers/AdminSettlementController.php`

- `store()` calls `GenerateProviderSettlementUseCase`.
- The approve / mark-paid / cancel methods still call `SettlementService` directly — that is the
  lifecycle state machine, deliberately left in place (ADR-P10-05).

### `app/Http/Controllers/AdminPaymentController.php`

- Replaces the referral attribution service locator with the injected
  `RecordReferralAttributionUseCase`.
- The `rejectPayment()` service locator is **intentionally left**: it performs a *cancellation*,
  which the attribution use case does not model.

### `app/Providers/AppServiceProvider.php`

- Registers `SettlementRepositoryInterface` and `PartnerCommissionRepositoryInterface`.

---

## 4. Boundary diagram

```
BEFORE
  BookingController ────────────► RevenueDistributionService ──► RevenueSplitResolverService
  ManualBookingService ─────────► RevenueDistributionService
  AdminSettlementController ────► SettlementService ──► BookingScreen::*  (inline query)
  SettlementService ────────────► BookingScreen::*     (inline writes)
  CommissionService ────────────► PartnerCommission::*  (3x lockForUpdate inline)
  ReferralTrackingService ──────► PartnerCommission::*  (lockForUpdate inline)
  AdminPaymentController ───────► app(ReferralTrackingService)  ← service locator

AFTER
  BookingController ────────────► DistributeBookingRevenueUseCase ──┐
  ManualBookingService ─────────► DistributeBookingRevenueUseCase ──┤
  ReferralTrackingService ──────► DistributeBookingRevenueUseCase ──┤
                                                                  │
  AdminSettlementController ────► GenerateProviderSettlementUseCase ► SettlementService
                                     │                              │
                                     │                              ▼
                                     │              SettlementRepositoryInterface
                                     │                              ▲
                                     │                              │
                                     │              EloquentSettlementRepository
                                     │
  AdminPaymentController ────────► RecordReferralAttributionUseCase ► ReferralTrackingService
  ManualBookingService ─────────► RecordReferralAttributionUseCase        │
                                                                         ▼
                                              PartnerCommissionRepositoryInterface
                                                                         ▲
                                                                         │
                                              EloquentPartnerCommissionRepository
                                                                         ▲
  CommissionService ─────────────────────────────────────────────────────┘
```

---

## 5. Architectural rules compliance

| Rule | Before | After |
|---|---|---|
| Domain framework-free | N/A | Pass — `RevenueAllocation` has zero framework imports |
| Vendor SDKs in infrastructure only | Pass | Pass |
| Explicit financial transaction boundaries | Documented only | Pass — every transaction documented in `phase-0.10-financial-transaction-map.json` |
| External network outside row locks | N/A in this phase | Pass (no network calls added) |
| Stable API contracts | Pass | Pass |

---

## 6. Preserved-on-purpose oddities

1. **Sub-paisa rounding drift** between `gross_revenue` and the sum of the two shares. Correcting it
   would retroactively alter every settled historical amount.
2. **The `bookingId` branch skips the period filter** entirely. A booking from a year ago is still
   settleable when explicitly targeted. Preserved and pinned by a test.
3. **Concurrent settlement generation can double-settle.** The eligibility read is outside the
   transaction and takes no lock. Recorded, not fixed.
4. **`RevenueDistributionService::getProviderRevenueStats()`** references an undefined variable. No
   callers. Not fixed.
5. **Application use cases still delegate to the legacy services.** Accepted transitional debt,
   documented per-class (ADR-P10-02).
6. **`ReferralTrackingService` depends on an application-layer use case** for its revenue backfill —
   an inverted dependency. Documented rather than restructured (ADR-P10-04).

---

## 7. Validation

| Check | Result |
|---|---|
| `php -l` on all modified files | Pass |
| `composer validate` | Pass |
| `php artisan migrate:status` | No pending migrations |
| `Tests\Feature\Golden\GoldenFinancialRegressionTest` | 3/3 pass |
| `Tests\Feature\Golden\GoldenFinancialBoundaryTest` (new) | 15/15 pass |
| `Tests\Unit\Domain\Financial\RevenueAllocationTest` (new) | 9/9 pass |
| `Tests\Feature\Golden\GoldenBookingRegressionTest` | 4/4 pass |
| Settlement snapshot-source invariant | Pinned by an explicit contradictory-percentage test |
| Commission `lockForUpdate` preservation | Pinned by test |
| Full suite | 451 pass, 2 fail (both pre-existing, unrelated) |
