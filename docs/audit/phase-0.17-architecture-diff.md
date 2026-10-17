# Phase 0.17 — Legacy Code and Controller Retirement: Architecture Diff

- **Date:** 2026-09-26
- **Project:** `illuvision/core-backend`
- **Type:** Deletion-only phase
- **Behaviour change:** none

## Summary

Phase 0.17 removed 16 dead production classes (324 lines) and one empty scaffolding
directory. No controller, route, middleware entry, model, migration, container
binding, event, command, job or service was added, renamed, edited or deleted. The
observable surface of the application is byte-for-byte identical.

## Structural change

```
DELETED
  app/Core/                          7 empty directories, 0 files
  app/Enums/ApprovalStatus.php
  app/Enums/AssetStatus.php
  app/Enums/CampaignStatus.php
  app/Enums/MediaType.php
  app/Enums/NotificationType.php
  app/Enums/PaymentStatus.php
  app/Enums/ProviderStatus.php
  app/Enums/SettlementStatus.php
  app/Enums/UserRole.php
  app/Exceptions/AssetUnavailableException.php
  app/Exceptions/BookingConflictException.php
  app/Policies/AssetPolicy.php
  app/Policies/PartnerBookingPolicy.php
  app/Services/Pricing/CampaignDiscountCalculator.php
  app/Services/Pricing/PeakPricingCalculator.php
  app/Services/Pricing/WeekendPricingCalculator.php

ADDED
  (none)

MODIFIED
  (none)
```

## Class census

| Layer        | Before | After | Δ    |
|--------------|--------|-------|------|
| Application  | 32     | 32    | 0    |
| Console      | 3      | 3     | 0    |
| Domain       | 9      | 9     | 0    |
| Enums        | 12     | 3     | **−9** |
| Events       | 34     | 34    | 0    |
| Exceptions   | 6      | 4     | **−2** |
| Helpers      | 1      | 1     | 0    |
| Http         | 81     | 81    | 0    |
| Infrastructure | 14  | 14    | 0    |
| Listeners    | 1      | 1     | 0    |
| Mail         | 28     | 28    | 0    |
| Models       | 62     | 62    | 0    |
| Observers    | 1      | 1     | 0    |
| Policies     | 2      | 0     | **−2** |
| Providers    | 2      | 2     | 0    |
| Repositories | 4      | 4     | 0    |
| Services     | 51     | 48    | **−3** |
| Support      | 9      | 9     | 0    |
| **Total**    | **352**| **336**| **−16** |

`app/Enums` retains `AssetType`, `BookingStatus`, `PricingUnit` — all live.

## Dependency-graph impact

Deleting unreferenced leaves removes no edge. No cycle was created or destroyed.
`composer dump-autoload` completed cleanly and the architecture test still passes
14/14 with 17 assertions, so Rule 2, Rule 11 and Rule 12 remain satisfied.

## Verification

| Check                | Result                                       |
|----------------------|----------------------------------------------|
| Full test suite      | 571 passed, 2 failed, 1 warning, 4 skipped (identical to baseline) |
| Assertions           | 5,323 (identical)                            |
| Routes               | 385, 0 differences in method/URI/action/middleware |
| Schema               | 74 tables, 796 columns, 252 indexes, 94 FKs (identical) |
| Migrations           | 112 applied, 0 pending                       |
| Dangling references  | 0                                            |

## Corrections to earlier phases

Two Phase 0.13 conclusions were revisited and corrected with runtime evidence:

1. **Two policies were dead, not live.** `AssetPolicy` and `PartnerBookingPolicy`
   were retained in Phase 0.13 on a Gate-convention assumption. `Gate::getPolicyFor()`
   returns a policy for *any* class string whose conventional policy name exists — so
   the non-existent `App\Models\Asset` resolved to `AssetPolicy`. No model in the
   application resolves to either policy; both are dead.

2. **The three "dead" scheduled commands are live.** A first detector pass flagged
   `ExpirePendingBookings`, `ExpireRewardPoints` and `SyncExchangeRates` as dead
   because their class names do not appear in their Artisan signatures
   (`bookings:expire-pending`, `rewards:expire`, `currency:sync-rates`). Runtime
   registration proves all three are scheduled and running. This reproduces the exact
   failure mode Phase 0.13 warned about, and is recorded here so the next inventory
   does not repeat it.

## Net assessment

The codebase is 16 classes smaller with no behavioural, financial, contractual or
schema difference. The four unused models over live tables
(`CoverageCity`, `MobileAssetCoverageCity`, `MobileAssetCoverageState`,
`PricingDiscountRule`) were deliberately **retained** — see
`phase-0.17-dead-code-verification.json`.
