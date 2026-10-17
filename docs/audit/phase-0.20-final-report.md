# Phase 0.20 Final Report - Physical Architecture Migration (Controlled)

**Date:** 2026-09-26  
**Status:** PHASE 0.20 COMPLETE WITH CONTROLLED MIGRATION DEBT  
**Overall Project Status:** PHASE 1 BLOCKED (SEC-01, SEC-02, SEC-03, DEP-01 remain open)  

## Summary
- Classes migrated: **64** (5 batches)
- Consumers updated: **198**
- Same-namespace imports inserted: **0**
- Routes: **IDENTICAL** (385 before, 385 after)
- Full test suite: **571 passed, 2 failed, 1 warning, 4 skipped, 5,323 assertions** (2 failures + 1 warning are pre-existing, message-wording + tautological)
- ArchitectureRuleTest: **14 passed, 17 assertions** (PASS)
- Database: **74 tables, 94 FKs, 112 migrations, 0 views** (unchanged)

## Batches
- **Batch 1 - Infrastructure** — Notification, Media/Storage adapters (4 moved)
- **Batch 2a - Events** — Notifications events + AssetCreated (34 moved)
- **Batch 2b - Exceptions & Enums** — Domain exceptions + BookingStatus/PricingUnit (6 moved)
- **Batch 2c - Services** — Campaign/Booking/Payment/Settlement services (14 moved)
- **Batch 2d - Repositories + Application/Asset** — Asset/Booking repositories + 2 Application services -> Campaign (6 moved)

## Key Decisions (Section 22)
- **Application/**: PARTIALLY MIGRATED. `AssetDiscoveryFilterService`, `CoverageService` -> `Services/Campaign`. Remaining 32 classes (Booking/Pricing/Payment/Financial use cases + 17 Reporting read-side classes) are **DEFERRED** (P3/P4/Reporting). No new classes invented under Application/; the layer is being intentionally retired as per plan.
- **Repositories/**: `Asset`, `Booking` contracts+implementations moved to capability subdirectories (names unchanged). 4 capabilities have no repository yet (recorded P2).
- **Http/**: No new Admin/Partner/Provider subdirectories created (naming convention only, no route boundary). Api/Auth retained.
- **Infrastructure/**: `Auth/`, `Financial/`, `Media/` **retained** (not flattened). Notification adapter moved to `Notifications/`, Media storage adapter moved to `Storage/`.
- **Creative**: bounded domain inside Project 01; 9 subdomains created, intentionally empty. No Project 03 CMS tree created.

## Residual Controlled Debt
- **Domain->Models**: 6 Domain files import `App\Models` (9 import edges total). Not enforced by `ArchitectureRuleTest.php`. Tracked in `phase-0.20-boundary-violations.json` and `phase-0.20-domain-module-map.json` (P1). Requires repository extraction to fix.
- **Domain->Services**: 7 imports, pinned in `CampaignPricingEngine` (7 of `App\Services\*`). Debt count unchanged; `LoyaltyService` pin updated to new namespace.
- **Support->Services**: 7 imports (e.g. `Support/Configs/*` -> `SettingsService`). Recorded, not new.
- **27 Services remain flat** in `app/Services/` (e.g. `InventoryService`, `BookingWorkflowService`, `BookingCapacityService`, `ManualBookingService`). P3/P4 financial/transactional classes deliberately not migrated (risk concentration). Documented in `phase-0.20-legacy-directory-status.json`.
- **32 Application classes deferred** (no behaviour changes).
- **33 repository extraction candidates** (services doing Eloquent directly) — not extracted in Phase 0.20.
- **Service locators**: 11 call sites total (Models/Booking 7, ManualBookingService 3, BookingController 1). Not enforced. Tracked in `phase-0.20-service-locator-usage.json` (P2).
- **Pre-existing test failures**: 2 (BookingConcurrencyTest, CampaignBookingPricingIntegrityTest) — message wording drift. 1 warning (MediaHelperTest) — tautological assertion.

## Artifacts
This report references **21** Phase 0.20 artifacts in `docs/audit/` (see `phase-0.20-conformance-checklist.json` and individual files).