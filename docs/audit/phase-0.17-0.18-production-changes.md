# Phases 0.17–0.18 — Production Changes

- **Date:** 2026-09-26
- **Project:** `illuvision/core-backend`
- **Observable behaviour change:** none

## Files deleted (16)

| Path | Type | Lines |
|---|---|---|
| `app/Enums/ApprovalStatus.php` | enum | 11 |
| `app/Enums/AssetStatus.php` | enum | 11 |
| `app/Enums/CampaignStatus.php` | enum | 14 |
| `app/Enums/MediaType.php` | enum | 11 |
| `app/Enums/NotificationType.php` | enum | 14 |
| `app/Enums/PaymentStatus.php` | enum | 12 |
| `app/Enums/ProviderStatus.php` | enum | 12 |
| `app/Enums/SettlementStatus.php` | enum | 12 |
| `app/Enums/UserRole.php` | enum | 12 |
| `app/Exceptions/AssetUnavailableException.php` | exception | 19 |
| `app/Exceptions/BookingConflictException.php` | exception | 19 |
| `app/Policies/AssetPolicy.php` | policy | 54 |
| `app/Policies/PartnerBookingPolicy.php` | policy | 47 |
| `app/Services/Pricing/CampaignDiscountCalculator.php` | service | 62 |
| `app/Services/Pricing/PeakPricingCalculator.php` | service | 85 |
| `app/Services/Pricing/WeekendPricingCalculator.php` | service | 65 |

**Total:** 324 lines removed.

## Directories removed (2)

- `app/Core/` — 7 empty subdirectories, 0 files
- `app/Listeners/` — emptied by the subscriber move
- `app/Policies/` — emptied by the policy deletions

## Files moved (3)

| From | To | Namespace change |
|---|---|---|
| `app/Listeners/NotificationEventSubscriber.php` | `app/Support/Events/NotificationEventSubscriber.php` | `App\Listeners` → `App\Support\Events` |
| `app/Services/AssetDiscoveryFilterService.php` | `app/Application/Asset/Services/AssetDiscoveryFilterService.php` | `App\Services` → `App\Application\Asset\Services` |
| `app/Services/CoverageService.php` | `app/Application/Asset/Services/CoverageService.php` | `App\Services` → `App\Application\Asset\Services` |

No file content logic changed in any move — only the `namespace` declaration.

## Directories created (2)

- `app/Application/Asset/Services/`
- `app/Support/Events/`

## Files modified (3) — imports only

| File | Change |
|---|---|
| `app/Providers/EventServiceProvider.php` | `use App\Listeners\NotificationEventSubscriber` → `use App\Support\Events\NotificationEventSubscriber` |
| `app/Http/Controllers/Api/CoverageController.php` | `use App\Services\CoverageService` → `use App\Application\Asset\Services\CoverageService` |
| `app/Services/AssetService.php` | **+2 `use` statements** for the two migrated services |

`app/Services/AssetService.php` needed no rewrite for the same-namespace references it
held. That omission broke its constructor and was caught by container resolution; see
ADR-18-04.

## Not changed

- **Controllers (81)** — none moved, none edited
- **Routes (385)** — none added, removed or re-pointed
- **Middleware, FormRequests, API Resources** — untouched
- **Models (62)** — none moved, none deleted
- **Migrations (112)** — none added, removed or edited
- **Schema** — 74 tables / 796 columns / 252 indexes / 94 FKs, identical
- **Domain layer (9 classes)** — untouched; the 7 pinned Rule 2 imports are unchanged
- **Infrastructure (14 classes)** — untouched
- **Financial paths** — pricing, availability, booking, payment, refund, settlement,
  commission, revenue split: not opened
- **Tests** — no test was modified, added or deleted
- **Configuration** — `composer.json`, `config/*`, `.env*` untouched
- **Container bindings** — 35 before, 35 after

## Net

| Metric | Before | After |
|---|---|---|
| Production PHP classes | 352 | 336 |
| Services classes | 51 | 46 |
| Application classes | 32 | 34 |
| Support classes | 9 | 10 |
| Enums | 12 | 3 |
| Policies | 2 | 0 |
| Exceptions | 6 | 4 |
| Listeners | 1 | 0 |
| Routes | 385 | 385 |
| Tables | 74 | 74 |

**Net effect:** 16 dead classes removed, 3 live classes relocated into a module that
demonstrably owns them, 2 empty scaffolding directories removed, and zero observable
difference to any HTTP request, background job or scheduled task.
