# 02 — Current Architecture Assessment

**Generated:** 2026-09-26
**Classification:** This document mixes `[CURRENT FACT]`, `[INFERENCE]` and `[RECOMMENDATION]`. Every claim is marked.

---

## 1. What architecture the backend actually follows

`[CURRENT FACT]` The backend is a **Laravel 11 modular monolith** that is *mid-migration* from a layer-oriented structure to a domain-oriented one. It does not yet follow the target architecture in `docs/architecture/ARCHITECTURE_RULES.md` uniformly. The honest description is:

> A working monolith with a **correct Infrastructure boundary**, a **thin and inconsistent Application layer**, a **shallow Domain layer**, and a **dominant legacy Service layer** that still owns business logic, transactions, and most database access.

The migration is a documented strangler (Rule 7): new boundaries were cut (`Domain/Payment`, `Domain/Financial`, `Infrastructure/*`, `Application/Payment`, `Application/Reporting`) while the legacy `App\Services\*` classes were left in place as the transaction owners. That is why the dependency graph currently points the *wrong* way in places (`Domain -> Services`).

## 2. Controller → Service relationships

`[CURRENT FACT]` Controllers are mostly thin for the **customer** surface and fat for the **admin** surface.

Evidence:

- **Thin (good):** `BookingController` delegates campaign creation to `CreateCampaignBookingUseCase`; `PaymentController` delegates to 4 use cases; `ReportController` delegates to `ReportQueryRegistry`; `ProfileController` delegates to `ProfileService`.
- **Fat (violation):** `ScreenController` (1000+ lines) contains raw `DB` usage for cities/states/countries, PhpSpreadsheet export, phpword export, and file deletion. `DashBoardController` (700+ lines) contains entire analytics query blocks. `PartnerPortalController`, `ScreenProviderPortalController`, `ManualBookingService`'s controller `AdminManualBookingController` all contain business workflows.

`[INFERENCE]` **Impact on Creative:** a junior developer copying `ScreenController` or `DashBoardController` as a template will immediately violate Rule 1 and produce untestable, un-reusable code. Creative controllers must be built against the thin pattern (`PartnerPortalController` shape, `ReportController` shape) and must be pinned by a new architecture test.

## 3. Service → Model / Service → Service relationships

`[CURRENT FACT]` The service layer is the de-facto application layer and it talks to Eloquent directly.

Measured from `11-existing-service-reuse-analysis.json`:

```text
48 services total
  - direct Eloquent (DB:: / Model:: queries):           ~30
  - open DB::transaction:                                 10
  - use lockForUpdate:                                     1 (InventoryService)
  - call external APIs:                                    3
  - zero constructor dependencies ("god" by reach):        6
```

Top offenders by size: `Campaign\BudgetRecommendationService` (1445 LOC), `ManualBookingService` (647 LOC, 8 constructor deps + 8 models), `BookingCapacityService` (602 LOC), `AssetImportService` (565 LOC), `PricingRuleEngine` (506 LOC), `ScreenProviderService` (479 LOC).

`[CURRENT FACT]` Only **2 of 48** services reach persistence through a repository interface: `AssetService` (via `AssetRepositoryInterface`) and `CommissionService` / `SettlementService` (via domain-owned contracts).

`[INFERENCE]` **Impact on Creative:** Creative domain logic must not call these services. Doing so would drag booking semantics, revenue splits and booking status transitions into the creative bounded context. Creative needs its own thin application services with their own repositories.

## 4. Domain → Model and Domain → Service relationships

`[CURRENT FACT]` Two distinct violations exist, both documented and both pinned:

| Violation | Count | Enforcement | Register |
|---|---|---|---|
| `Domain -> Services` | 7 imports, all in `App\Domain\Pricing\CampaignPricingEngine` | **Enforced exactly** by `ArchitectureRuleTest` (adding an 8th fails; removing one fails) | `docs/audit/phase-0.14-architecture-debt-register.json` |
| `Domain -> Models` | 6 files / 9 import edges | **Not enforced** | `docs/audit/phase-0.20-boundary-violations.json` |
| `Support -> Services` | 7 imports (e.g. `Support\Configs\* -> SettingsService`) | Not enforced | Recorded in Phase 0.20 artifacts |

`[CURRENT FACT]` `ArchitectureRuleTest` is a static token-stream scanner (not regex) with 14 tests / 17 assertions, currently green. It pins pre-existing debt **exactly** (file + method + line) so debt cannot silently grow or silently vanish.

`[INFERENCE]` **Impact on Creative:** a new `app/Domain/Creative/**` tree must be written with **zero** `App\Models` and **zero** `App\Services` imports, from the first commit. Adding one will fail `ArchitectureRuleTest` (for Services) or silently deepen unenforced debt (for Models). The Creative team should add a dedicated `Domain/Creative -> App\Models` guard to the architecture test.

## 5. Infrastructure dependencies

`[CURRENT FACT]` This is the healthiest boundary in the repository.

```text
App\Domain\Payment\Contracts\PaymentGatewayInterface   -> RazorpayPaymentGateway
App\Infrastructure\Auth\Contracts\GoogleIdentityProviderInterface -> GoogleOAuthAdapter
App\Infrastructure\ExchangeRates\Contracts\ExchangeRateApiClientInterface -> ExchangeRateApiClient
App\Infrastructure\Notifications\Contracts\MailContractInterface -> LaravelMailAdapter
App\Infrastructure\Media\Contracts\ImageProcessingContractInterface -> GdImageProcessor
App\Infrastructure\Storage\Contracts\StorageContractInterface -> LaravelStorageAdapter
```

Leakage that still exists:

| Leak | Location | Rule |
|---|---|---|
| `Storage::` facade called directly | 10 sites: `Models\Screen:95`, `AuthorController:114,139`, `SuccessStoryController:71,74`, `ScreenController:284`, `GalleryController:72,75`, `ScreenProviderPortalController:412`, `JobApplicationController:65` | Rule 3 spirit |
| `UploadedFile::storeAs` direct | `FileController:29,44`, `AuthorController:64,117`, `Api\SettingsController:62,69`, `JobApplicationController:46` | Rule 1 |
| Raw `Http::` call | `Campaign\CountryDetectionService:40` | Rule 1 |
| PhpSpreadsheet / phpword in controller | `ScreenController:21-27` | Rule 1 |
| `file_put_contents` in a helper | `Helpers\MediaHelper:70` | Rule 1 |
| `DB` facade in services/controllers | pervasive | Rule 11 |

## 6. Transaction boundaries

`[CURRENT FACT]` The protected booking shape **is** implemented and pinned:

```text
check -> DB::transaction -> lockForUpdate() -> re-check -> mutate -> commit
```

Evidence: `InventoryService::lockScreensForBooking()` (`lockForUpdate`, ordered by `id` for deadlock avoidance, documented at lines 23-36), consumed by `CreateCampaignBookingUseCase` and `ManualBookingService`. `GoldenConcurrencyVerificationTest` asserts the lock exists; it also asserts that `PaymentService` retrieval deliberately does **not** use `lockForUpdate` (a pinned asymmetry).

`[CURRENT FACT]` Transaction violations found:

| Violation | Location | Impact |
|---|---|---|
| External gateway call **inside** a DB transaction | `RefundService::initiateRefund():51`, `PaymentService::refund():189` | Rule 4 violation; lock/connection held across network I/O |
| Multi-table write without a transaction | `SettlementService::approveSettlement():143`, `markAsPaid():176` (settlement update + activity log) | Partial-write risk |
| Event dispatched inside a transaction | `ManualBookingService::recordOfflinePayment():642` (`PaymentReceived`) | Notification may be sent for a rolled-back write |
| No transaction at all | `BookingController::markManualPayment():1030` (direct `Payment::create`) | Divergent offline-payment path; no referral attribution |
| Guard read outside the transaction | `ScreenDeletionService::deleteScreens():98` (validate, then commit at 140) | A booking created between read and commit is undetected |

## 7. Authorization boundaries

`[CURRENT FACT]` Authorization is enforced at the **route/middleware boundary** (Rule 12). `PermissionMiddleware` resolves permissions through the single algorithm owner `RolePermissionChecker`, which matches `users.role` (a free-form varchar) against `roles.slug` and reads granted permissions from `role_permissions`, cached for 3600 s.

Structural facts that matter for Creative:

- **No `app/Policies` directory and no `Gate::define` anywhere.** There is no policy layer.
- All 5 Form Requests return `true` from `authorize()`.
- Ownership checks are ad-hoc inside controllers: `BookingController:116` and `PaymentController:48` check `$booking->user_id !== $request->user()->id`; `PartnerBookingController:250` checks `created_by_partner_id`.
- Portal entry points additionally hard-code role strings: `PartnerPortalController:257`, `PartnerBookingController:333`, `PartnerClientController:183`, `ScreenProviderPortalController:840` all compare `$user->role !== 'partner' | 'screen_provider'`.
- The **super-admin bypass lives only in `PermissionMiddleware:28`**, not in `RolePermissionChecker` or `User::hasPermission()`. This asymmetry is intentional and test-pinned (`AuthorizationHardeningTest::test_super_admin_bypasses_permission_checks`).
- `users.role` is a **varchar with no FK and no enum**. A misspelled role silently yields an empty permission set.
- `partner` is used as a role string but is **not seeded** in `RolesAndPermissionsSeeder`; it has no granted permissions. Portal access is granted purely by the hard-coded role checks.
- `settlements.view`, `settlements.approve`, `settlements.pay` are used by routes but **not present in the seeder vocabulary** — documented debt pinned by `Sec03PermissionVocabularyTest`.

`[INFERENCE]` **Impact on Creative:** Creative must introduce the **first policy layer** in this repository, or it will repeat the ad-hoc ownership pattern at triple the scale (every brief, proposal, project, revision, delivery and review is an owner-scoped object). That is a justified architectural addition, not a violation of Rule 12 — Rule 12 fixes the *authoritative* check at the route boundary; a policy adds the *ownership* check that currently does not exist. Both must be present.

## 8. Event-driven behaviour, queues and scheduled jobs

`[CURRENT FACT]`

- 34 event classes: 1 domain event (`AssetCreated`, **dead** — dispatched by `AssetObserver:18`, consumed by nobody) + 33 notification events in `App\Support\Events\Notifications`.
- **No event implements `ShouldQueue`.** All 33 are handled synchronously on the request thread.
- **There is no `app/Jobs` directory and no queued job class anywhere.** All 28 Mailables implement `ShouldQueue`, so only the final mail hand-off is deferred.
- One subscriber, `NotificationEventSubscriber` (385 lines, 33 `listen()` calls), registered via `EventServiceProvider::$subscribe`.
- Notification dedup is an **in-process `static array`** (`$processed`), which resets every request and is **not** durable across queue workers, retries, or additional web nodes. 4 of 33 handlers have no dedup call at all (`handlePartnerApproved`, `handlePartnerRejected`, `handleScreenProviderApproved`, `handleScreenProviderRejected`).
- `NotificationService::send()` resolves recipients only as `admin` / `user` / `partner`. There is **no `creator` recipient role**.
- `NotificationService` swallows mail failures (`try/catch \Throwable` at line 167), so a dispatching controller always sees success.
- Event keys are enumerated in **three** places in `NotificationConfig` (`availableEvents`, `eventLabels`, `eventDefaults`, 35 keys each). A new key missing from any one of them silently falls back to `defaultConfig()` = enabled-but-delivered-to-nobody.
- 4 scheduled commands; only `bookings:expire-pending` is a genuine state-machine sweeper.

`[INFERENCE]` **Impact on Creative:** the notification pipeline is reusable but must be **extended, not joined**. A new `creator` recipient role, new `creative_*_config` settings groups, new Mailables, and new events are all required. A Creative event dispatched from inside a Creative transaction will synchronously run settings resolution, recipient resolution and log writes on the request thread — the same pattern as today, with the same durability limits.

## 9. Duplicated logic

`[CURRENT FACT]` Duplications that a Creative developer must not inherit:

| Duplication | Locations | Note |
|---|---|---|
| Two offline-payment paths | `ManualBookingService::recordOfflinePayment():577` vs `BookingController::markManualPayment():1030` | Different transactions, different referral attribution, different response envelope. Explicitly recorded as deferred debt. |
| Two payment status vocabularies on one column | `Payment::STATUS_*` (`verification_pending`, `paid`) vs `Payment::PAYMENT_STATUS_*` (`awaiting_verification`, `completed`) | Both are live in code. |
| Two "source" columns on `bookings` | `source` + `booking_source` | Overlapping semantics. |
| Legacy vs new pricing engines | `Services\PricingEngine` -> `CampaignPricingService` -> `PricingRuleEngine` and `Domain\Pricing\CampaignPricingEngine` | The `LegacyPricingEngineAdapter` keeps both alive. |
| Duplicated commission snapshots | `referral_usages` and `partner_commissions` both store the same 5 profit columns | Deliberate redundancy for immutability; not a defect. |
| `FinanceService` platform margin constant | `FinanceService:53` hard-coded `0.70` | A financial magic number. **Must never be copied into Creative.** |
| Envelope inconsistency | 6 distinct response shapes across controllers | See `14-api-architecture-audit.md`. |

## 10. Architectural violations and their Creative impact

`[CURRENT FACT]` violations confirmed in source, with the specific consequence for a Creative developer:

| # | Violation | Evidence | Creative consequence |
|---|---|---|---|
| V-01 | `Asset` is a **namespace collision**: `app/Domain/Asset` and `AssetService`/`AssetRepository` mean *ad inventory screens*, not creative work files | `Models\Screen` is the asset; `AssetService::getAsset(): Screen` | A Creative class named `CreativeAsset` will be confused with `Domain\Asset`. Use `CreativeFile`/`PortfolioItem` naming, never `Asset`. |
| V-02 | `App\Services\*` still owns transactions and business rules | 30/48 services do Eloquent directly | Creative domain code must not import `App\Services`. |
| V-03 | No policy layer | no `app/Policies` | Creative needs the first one; see §7. |
| V-04 | `users.role` is free-form varchar with no FK/enum | `users` migration + `2026_07_10_000002` converted enum -> varchar | A `creator` role added by seed only works if a `roles` row **and** `role_permissions` rows exist. A typo silently denies. |
| V-05 | Permission cache TTL 3600 s | `RolePermissionChecker::CACHE_TTL_SECONDS` | New creator permissions take up to an hour to take effect unless `clearCache()` is called — a confusing support burden during Creative rollout. |
| V-06 | 6 different response envelopes | see `14-api-architecture-audit.md` | Creative must pick the base `Controller::successResponse` and be pinned. |
| V-07 | `App\Application` is being retired yet holds 32 classes | `phase-0.20-final-report.md` | Creative must **not** place classes in `app/Application`. Use `app/Services/Creative` (application) + `app/Domain/Creative` (domain). |
| V-08 | External HTTP/gateway calls inside transactions | `RefundService:51`, `PaymentService:189` | Creative funding/refund flows must not copy this shape. |
| V-09 | Path traversal + arbitrary extension write on the public disk | `FileController:13,29,44` (no validation, `$request->path` used raw) | Creative file intake must be built through `StorageContractInterface` with validation at the Form Request. Never extend `FileController`. |
| V-10 | No idempotency mechanism exists anywhere | 0 matches for `Idempotency` in `app/` | Creative money and state transitions need a designed idempotency approach from scratch. |
| V-11 | No webhook endpoint exists | 0 webhook routes | Creative funding confirmation is client-initiated only, unless new work builds a webhook. |
| V-12 | No signed URLs; every file is a permanent public URL | 0 matches for `temporarySignedRoute`/`temporaryUrl` | Creative briefs and unpublished deliverables are sensitive. Private access is **new work**. |
| V-13 | Service locator call sites | 11 sites incl. `RevenueSplitResolverService:210`, `ExchangeRateService:23` | Do not copy `app(...)` into Creative code. |
| V-14 | 3 "portals" exist with duplicated, non-abstracted shape | `PartnerPortalController` vs `ScreenProviderPortalController`, ~400 LOC each | A Creative portal will be the third copy. Extract a shared portal pattern **first** or accept the third duplication deliberately. |

## 11. Summary assessment

`[INFERENCE]`

```text
Infrastructure boundary      : STRONG   — reuse directly through contracts
Application layer            : PARTIAL  — exists for Payment/Financial/Reporting only
Domain layer                 : SHALLOW  — 15 classes; Pricing and Payment only; 2 pinned violations
Repository layer             : THIN     — 4 classes; 2 capabilities only
Service layer                : LEGACY   — 48 classes, owns transactions and business rules
Authorization                : WORKING but INCOMPLETE — no policy layer, free-form roles
API conventions              : INCONSISTENT — 6 response envelopes, 3 pagination keys
Event/notification pipeline  : WORKING but NON-DURABLE — in-process dedup, no jobs
Storage                      : UNSAFE    — public disk, no signed URLs, unauthenticated upload routes
Money                        : SOLID primitives, TIGHT coupling to bookings
Creative capability          : ABSENT   — 0 classes, 0 tables, 0 routes
```

`[RECOMMENDATION]` Creative Marketplace should be built as a **new, fully-conforming vertical slice** (pure domain + repositories + application services + thin controllers + policy layer), and should reach into the existing system only through the **Infrastructure contracts** and the small set of services that are genuinely generic. It must not be built by copying the `ScreenProvider` portal, and it must not be bolted onto `App\Services`.
