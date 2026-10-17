# Illuvision Core Backend
# Phase 0.2 — Service & Dependency Mapping

**Project:** Illuvision Core Backend Re-Architecture  
**Phase:** 0.2 — Service & Dependency Mapping  
**Baseline Reference:** Phase 0.1 API & Route Contract Baseline (385 routes locked)  
**Framework:** Laravel 11.31 | PHP ^8.2 | MySQL 8.0+  
**Execution Timestamp:** 2026-09-25T20:58:00+05:30  
**Guiding Principle:** *"Refactor the Implementation, Not the External Contract."*  

---

## 1. Executive Summary

Phase 0.2 establishes a comprehensive, evidence-based dependency map of the internal architecture of the Illuvision Core Backend (`core-backend`). 

Following the successful completion of Phase 0.1 (which locked all 385 API route contracts and verified zero route regression), Phase 0.2 maps how controllers, services, repositories, models, database transactions, external integrations, and background events actually interact at runtime.

### Key Architectural Discoveries:

1. **Component Reality Baseline:**
   - **69 Controllers** (2 God Controllers: `BookingController` at 1,826 lines and `ScreenController` at 1,065 lines).
   - **52 Services** (45 in `app/Services/` and 7 in `app/Services/Pricing/`).
   - **4 Repository Files** (2 implementations + 2 interfaces): `AssetRepository` and `BookingRepository`.
   - **62 Eloquent Models** (8 verified beyond the initial 54 baseline: `CoverageCity`, `MobileAssetCoverageCity`, `MobileAssetCoverageState`, `AdvertisementCategory`, `ScreenCategory`, `ScreenDurationSplitOverride`, `PricingScreenRule`, `PricingDurationRule`).
   - **8 Explicit Database Transaction Boundaries** (`DB::transaction`).
   - **5 External Integrations** (Razorpay SDK, Google OAuth Tokeninfo, ExchangeRate-API, Local/GD Filesystem, SMTP/Email).
2. **Pricing Engine Bifurcation:**
   - The authoritative pricing engine is `CampaignPricingService` + `PricingRuleEngine`.
   - A divergent engine exists in `PricingEngine` (`calculatePreview()`), which hardcodes day divisions and a flat 10% 30-day discount, ignoring database rules despite injecting `CampaignPricingService`.
3. **Repository Layer Bypassed (96% Direct Model Access):**
   - 48 of the 52 services directly execute Eloquent static methods (`Screen::`, `Booking::`, `Payment::`), bypassing repository interfaces.
4. **Clean Decoupling from CMS SaaS (Project 03):**
   - The backend contains zero physical player communication, zero signage playback daemons, and zero shared database schemas with the CMS.
5. **Creative Marketplace (Project 02) Missing:**
   - Currently, no creator profiles, portfolios, briefs, or milestone escrow models exist.

---

## 2. Scope

The scope of Phase 0.2 encompasses:
- Mapping all 69 controllers to their invoked services, models, and direct DB operations.
- Mapping all 52 services to their upstream callers, downstream dependencies, and external SDKs.
- Tracing all pricing, availability, booking, payment, settlement, and commission calculation paths.
- Documenting every database transaction boundary (`DB::transaction`) and row-locking mechanism (`lockForUpdate`).
- Mapping all 38 domain events, 1 event subscriber, 28 mailables, and 1 queued job.
- Generating 6 machine-readable JSON artifacts in `docs/audit/`.
- **Zero code refactoring, zero test alterations, and zero schema changes.**

---

## 3. Methodology

Static analysis of the entire PHP codebase was conducted using automated AST parsing and regex extraction scripts:
1. **Dependency Extraction:** Parsed typed constructor property promotions (`public function __construct(...)`) and runtime service resolutions (`app(...)`, `new ...`).
2. **Data Touchpoint Tracing:** Identified Eloquent model queries (`::where`, `::find`, `::create`, `::update`), query builder calls (`DB::table(...)`), and raw SQL expressions.
3. **Concurrency Analysis:** Traced all `DB::transaction()` blocks, pessimistic locks (`lockForUpdate()`), and lock release triggers.
4. **Integration Auditing:** Located direct external network calls (Razorpay SDK, Google OAuth HTTP endpoint, ExchangeRate API HTTP endpoint, and raw GD image compression).

---

## 4. Current Backend Component Inventory

| Component Category | Verified Count | Storage Location | Key Architectural Characteristics |
| :--- | :--- | :--- | :--- |
| **Controllers** | 69 | `app/Http/Controllers/` | 2 god controllers, 5 moderately fat, 62 thin/delegating |
| **Services** | 52 | `app/Services/` & `app/Services/Pricing/` | 12 domain orchestrators, 22 entity CRUD services, 18 analytics/calculators |
| **Repositories** | 4 (2 impl + 2 intf) | `app/Repositories/` | `AssetRepository` (active), `BookingRepository` (underutilized) |
| **Eloquent Models**| 62 | `app/Models/` | 75 database tables mapped; high model relationship fidelity |
| **Form Requests** | 5 | `app/Http/Requests/` | `CreateAssetRequest`, `UpdateAssetRequest`, `PricingPlanRequest`, `RoleRequest`, `SettingsRequest` |
| **Events** | 38 | `app/Events/` & `app/Events/Notifications/` | `AssetCreated` + 37 notification domain events |
| **Listeners** | 1 | `app/Listeners/` | `NotificationEventSubscriber` (subscribes to 32 events) |
| **Jobs** | 1 | `app/Jobs/` | `GenerateAssetCodeJob` (queued asset code generator) |
| **Mailables** | 28 | `app/Mail/` | Markdown and view-based transactional mailers |
| **Helpers** | 1 | `app/Helpers/` | `MediaHelper.php` (GD image compression & filesystem write) |
| **Policies** | 2 | `app/Policies/` | `AssetPolicy.php`, `PartnerBookingPolicy.php` |
| **Middleware** | 1 | `app/Http/Middleware/` | `PermissionMiddleware.php` (cached custom RBAC) |
| **Commands** | 3 | `app/Console/Commands/` | `ExpirePendingBookings`, `ExpireRewardPoints`, `SyncExchangeRates` |

---

## 5. Controller → Service Map

A comprehensive mapping of all 69 controllers and their methods to services, repositories, and models is recorded in `docs/audit/controller-service-map.json`.

### High-Impact Controller Flows:

#### 1. `BookingController@storeCampaign`
- **Route:** `POST /api/bookings/campaign`
- **Services Invoked:**
  - `BookingCapacityService::checkEligibility` (Line-sweep fleet capacity & fixed slot checks)
  - `CampaignPricingService::validateCampaignPricing` (Authoritative pricing re-evaluation)
  - `LoyaltyService::debitRedeemedPoints` (Debits points if redeemed)
  - `InventoryService::lockScreensForBooking` (Pessimistic row locks)
- **Models Touched:** `Booking`, `BookingScreen`, `BookingPricingBreakdown`, `BookingActivity`, `Screen`, `AdvertisementCategory`, `RewardAccount`.
- **Database Behavior:** Wraps insertion of booking, screens, snapshots, and activity logs in `DB::transaction()`.

#### 2. `AdminManualBookingController@store`
- **Route:** `POST /api/admin/manual-bookings`
- **Services Invoked:**
  - `ManualBookingService::createCampaignBooking`
- **Models Touched:** Delegated to `ManualBookingService`.

#### 3. `PartnerBookingController@store`
- **Route:** `POST /api/partner/bookings`
- **Services Invoked:**
  - `ManualBookingService::createCampaignBooking` (Injected as `$delegatedBookingEngine`)
  - `ManualBookingService::findOrCreateClient`
- **Models Touched:** `Partner`, `User`, `Booking`.

#### 4. `PaymentController@verify`
- **Route:** `POST /api/payments/razorpay/verify`
- **Services Invoked:**
  - `PaymentService::verifyAndConfirmPayment`
- **Events Dispatched:** `PaymentReceived` on success, `PaymentFailed` on failure.

---

## 6. Service Dependency Graph

The complete graph is recorded in `docs/audit/service-dependency-map.json`.

### High Fan-Out Services (Highest Dependencies)
1. **`ManualBookingService` (7 dependencies):**
   - `CampaignPricingService`
   - `BookingCapacityService`
   - `InventoryService`
   - `MediaService`
   - `PaymentService`
   - `RevenueDistributionService`
   - `RevenueSplitResolverService`
2. **`CampaignPricingService` (7 dependencies):**
   - `ScreenPricingResolver`
   - `DailyPricingCalculator`
   - `ReferralPricingCalculator`
   - `FirstBookingDiscountPricingCalculator`
   - `PricingRuleEngine`
   - `LoyaltyService`
   - `SettingsService`
3. **`FinanceService` (4 dependencies):**
   - `PaymentService`
   - `SettlementService`
   - `CommissionService`
   - `RefundService`

### High Fan-In Services (Most Inbound Callers)
1. **`CampaignPricingService`:** Called by `BookingController`, `ManualBookingService`, `BudgetRecommendationService`, `PaymentService`, `PricingEngine`.
2. **`BookingCapacityService`:** Called by `BookingController`, `ManualBookingService`, `AvailabilityEngineController`, `BudgetRecommendationService`.
3. **`RevenueSplitResolverService`:** Called by `BookingController`, `ManualBookingService`, `RevenueDistributionService`, `ScreenController`.
4. **`PaymentService`:** Called by `PaymentController`, `AdminPaymentController`, `ManualBookingService`, `FinanceService`.

### Circular Dependency Analysis
- **Direct Constructor Cycles:** **NONE**. PHP constructor injection cycles would cause instant runtime fatal errors; none exist.
- **Soft Runtime Cycle Detected:**
  - `CampaignPricingService` injects `LoyaltyService` in constructor.
  - `LoyaltyService` resolves `CampaignPricingService` dynamically at runtime via `app(CampaignPricingService::class)` in its bonus calculation logic.
  - *Mitigation Plan for Future Refactor:* Decouple loyalty discount calculation into an independent `LoyaltyDiscountCalculator`.

---

## 7. Business Rule Ownership

The authoritative ownership matrix is recorded in `docs/audit/business-rule-ownership.json`.

| Domain | Business Rule | Authoritative Owner | Secondary / Duplicate Owner | Duplication Risk |
| :--- | :--- | :--- | :--- | :--- |
| **Pricing** | Multi-Asset Pricing Preview | `CampaignPricingService` | `PricingEngine` (Hardcoded day math) | **CRITICAL** |
| **Pricing** | Duration Unit Normalization | `PricingRuleEngine` | `PricingEngine` (Inline division) | **HIGH** |
| **Pricing** | Duration Split Overrides | `RevenueSplitResolverService` | *None* | Sole Authority |
| **Availability** | Mobile Fleet Concurrency | `BookingCapacityService` | `InventoryService` (Partial count) | **CRITICAL** |
| **Availability** | Fixed Screen Slot Collision | `BookingCapacityService` | `ConflictDetectionService` | **HIGH** |
| **Availability** | 30m Hold Expiration | `ExpirePendingBookings` Command | *None* | Sole Authority |
| **Booking** | Campaign Booking Creation | `ManualBookingService` | `BookingController@store` | **CRITICAL** |
| **Payment** | Order Creation & Verification| `PaymentService` | *None* | Sole Authority |
| **Settlement** | Provider Settlement Batches | `SettlementService` | *None* | Sole Authority |
| **Commission** | Partner Referral Tracking | `ReferralTrackingService` | `CommissionService` | Sole Authority |
| **Loyalty** | IlluPoints Earning/Redeem | `LoyaltyService` | *None* | Sole Authority |
| **Currency** | FX Rate Synchronization | `ExchangeRateService` | *None* | Sole Authority |

---

## 8. Pricing Dependency Audit

```
                              [ HTTP Controllers ]
                                  /            \
          POST /api/campaigns/calculate     POST /api/v2/pricing/calculate
                     |                                     |
                     v                                     v
         [ CampaignPricingService ]                 [ PricingEngine ]
         /           |            \                        |
        v            v             v                       v
[ PricingRuleEngine ] [ Resolvers ] [ Calculators ]   (Hardcoded Day Math:
 (Minute Normalization) (Screen/Plan) (Peak/Weekend/    /30, /7, *24, 10% Disc)
                                       Discounts)      [ Bypasses Rules DB ]
```

### Trace Evidence:
- **Canonical Execution Path:** `POST /api/campaigns/calculate` -> `BookingController@calculateCampaignPricing` -> `CampaignPricingService::calculateCampaignPreview`. This path evaluates:
  1. `ScreenPricingResolver`: Screen pricing plan association.
  2. `PricingRuleEngine`: Minute normalization.
  3. `DailyPricingCalculator`: Iterates every day in the campaign window, checking for peak hours (`PricingPeakRule`) and weekend multipliers (`PricingWeekendRule`).
  4. `CampaignDiscountCalculator`: Duration tiered discounts (`PricingDurationRule`) and multi-screen quantity discounts.
  5. `ReferralPricingCalculator` & `FirstBookingDiscountPricingCalculator`: User-level discounts.
  6. Statutory 18% GST calculation added to final net amount.
- **Divergent Path:** `POST /api/v2/pricing/calculate` -> `PricingEngineController@calculate` -> `PricingEngine::calculatePreview`.
  - Code Evidence in `PricingEngine.php` line 45-50:
    ```php
    $unitPricePerDay = match ($unitType) {
        'month' => $basePrice / 30.0,
        'week' => $basePrice / 7.0,
        'hour' => $basePrice * 24.0,
        'minute' => $basePrice * 1440.0,
        default => $basePrice,
    };
    ```
  - Line 68: `$durationDiscount = $days >= 30 ? round($grossTotal * 0.10, 2) : 0.0;`
  - It completely ignores peak rules, weekend rules, duration rules, and referral codes.

---

## 9. Availability Dependency Audit

```
                            [ Availability Invocations ]
                                 /                \
        POST /api/v2/availability/check    POST /api/bookings/check-conflicts
                     |                                     |
                     v                                     v
         [ BookingCapacityService ]               [ InventoryService ]
         (Authoritative Line-Sweep)               (Direct DB Overlap Queries)
```

### Trace Evidence:
- **Canonical Availability Authority:** `BookingCapacityService::checkEligibility`.
  - Line-sweep algorithm: Breaks multi-asset campaign windows into chronological minute timestamps, increments requested fleet quantity on start, decrements on end, and checks if peak concurrency exceeds `fleet_size`.
  - Fixed screen concurrency: Verifies whether target screen bookings for the day exceed `daily_limit` or have overlapping intraday minutes.
- **Secondary / Divergent Path:** `BookingController@checkConflicts` directly calls `InventoryService::getAvailableFleet` for mobile screens instead of `BookingCapacityService`.

---

## 10. Booking Dependency Audit

There are **four separate booking creation entry points**:

1. **Advertiser Web Booking:** `BookingController@storeCampaign`
   - 200 lines of inline Eloquent queries creating `Booking`, `BookingScreen`, `BookingPricingBreakdown`, and `BookingActivity`.
   - Initial status: `payment_pending`.
2. **Partner Delegated Booking:** `PartnerBookingController@store`
   - Delegates to `ManualBookingService::createCampaignBooking`.
   - Injects partner ID into `created_by_partner_id`.
3. **Admin Assisted Sales Booking:** `AdminManualBookingController@store`
   - Delegates to `ManualBookingService::createCampaignBooking`.
   - Records pre-collected manual offline payments.
4. **V2 Multi-Asset Booking:** `Api\BookingEngineController@store`
   - Executes custom transaction in controller; fails to create provider split snapshots.

---

## 11. Payment & Finance Dependency Audit

```
[ Customer Checkout ]
        ↓
PaymentController@createOrder
        ↓
PaymentService::createOrder
        ↓ (Creates Razorpay Order via SDK in paise)
[ Razorpay Checkout Modal ]
        ↓ (Returns order_id, payment_id, signature)
PaymentController@verify
        ↓
PaymentService::verifyAndConfirmPayment  <--- [ DB::transaction() Boundary ]
        ├── HMAC SHA256 Signature Verification
        ├── Update payments.payment_status = 'paid'
        ├── Update bookings.status = 'pending_approval'
        ├── ReferralTrackingService::createReferralTracking()
        │      └── Creates referral_usages & partner_commissions
        └── Dispatches PaymentReceived Event
               └── NotificationEventSubscriber -> Sends Confirmation Email
```

### Trace Evidence:
- **Order Creation:** `PaymentService::createOrder` converts `booking->final_amount` to paise (`* 100`) and calls `$this->razorpay->order->create(...)`.
- **Payment Verification:** `PaymentService::verifyAndConfirmPayment` runs inside a database transaction, verifying signature via `utility->verifyPaymentSignature(...)`.
- **Settlement Persistence:** `SettlementService::generateSettlement` reads `booking_screens.screen_provider_revenue_amount` directly. It never recalculates from current percentages.

---

## 12. Database Touchpoint Map

The complete mapping of 259 touchpoints across all services and controllers is documented in `docs/audit/database-touchpoint-map.json`.

### Most Heavily Accessed Tables:
1. `screens`: Read by 28 controllers/services, updated by 6, locked by 3 (`InventoryService`, `ManualBookingService`, `BookingEngineController`).
2. `bookings`: Read by 22 controllers/services, created by 4, updated by 9, status transitioned by 5.
3. `booking_screens`: Created by 3 booking paths, read by 8 settlement and analytics services.
4. `payments`: Created by `PaymentService`, updated by `PaymentService` & `AdminPaymentController`, read by `FinanceService`.
5. `partner_commissions`: Created by `ReferralTrackingService`, approved/paid by `CommissionService`.
6. `screen_provider_settlements`: Created and transitioned by `SettlementService`.

---

## 13. Transaction & Concurrency Map

The 8 explicit transaction boundaries are documented in `docs/audit/transaction-boundary-map.json`.

| Operation | Initiator Location | Locks Acquired | Tables Mutated | Concurrency Risk |
| :--- | :--- | :--- | :--- | :--- |
| **Manual Booking Creation** | `ManualBookingService::createCampaignBooking` | `Screen::lockForUpdate()` | `bookings`, `booking_screens`, `booking_pricing_breakdowns`, `payments` | **CRITICAL** |
| **Web Campaign Creation** | `BookingController@storeCampaign` | `InventoryService::lockScreensForBooking` | `bookings`, `booking_screens`, `booking_pricing_breakdowns` | **CRITICAL** |
| **V2 Booking Creation** | `BookingEngineController@store` | `InventoryService::lockScreensForBooking` | `bookings`, `booking_screens` | **CRITICAL** |
| **Payment Confirmation** | `PaymentService::verifyAndConfirmPayment` | `Payment::firstOrFail()` | `payments`, `bookings`, `referral_usages`, `partner_commissions` | **CRITICAL** |
| **Payment Refund** | `PaymentService::refund` | `Payment::findOrFail()` | `payments`, `bookings`, `refunds` | **HIGH** |
| **Commission Approval** | `CommissionService::approve` | `PartnerCommission::lockForUpdate()` | `partner_commissions` | **HIGH** |
| **Commission Paid Mark** | `CommissionService::markAsPaid` | `PartnerCommission::lockForUpdate()` | `partner_commissions` | **HIGH** |
| **Settlement Transition** | `SettlementService::transitionBatchStatus` | `ScreenProviderSettlement::lockForUpdate()` | `screen_provider_settlements`, `booking_screens` | **HIGH** |

---

## 14. Events / Jobs / Side Effects

### Event Flow:
- **Events (38 files):** Located in `app/Events/` and `app/Events/Notifications/`.
- **Subscriber:** `NotificationEventSubscriber.php` listens to 32 notification events.
- **In-Memory Deduplication:** Uses `NotificationEventSubscriber::deduplicate()` to prevent duplicate emails during rapid multi-state transitions.
- **Queued Jobs (1 file):** `GenerateAssetCodeJob.php` (`ShouldQueue`). Dispatched by `AssetObserver` on screen creation to assign `AST-XXXXX` codes.

---

## 15. External Integration Map

The complete external integration audit is documented in `docs/audit/external-integration-map.json`.

| External Service | Direct Caller | SDK / Protocol | Config Keys | Failure Mode |
| :--- | :--- | :--- | :--- | :--- |
| **Razorpay API** | `PaymentService` | `Razorpay\Api\Api` (PHP SDK) | `services.razorpay.key`, `secret` | Throws `Exception`, logged to `laravel.log`, payment marked `failed` |
| **Google OAuth** | `GoogleAuthController` | `Http::get('oauth2.googleapis.com')` | *None* | Throws `ValidationException` (HTTP 422) |
| **ExchangeRate-API** | `ExchangeRateService` | `Http::get('api.exchangerate-api.com')` | *None* (free tier) | Falls back to hardcoded `baselineRates` |
| **Local / GD Storage**| `MediaHelper` | Raw PHP GD (`imagewebp`, `copy`) | `filesystems.disks.public` | Copies uncompressed file if GD fails |
| **SMTP / Email** | `NotificationService` | Laravel `Mail::to()` | `mail.mailers.smtp.*` | Logs failure to `notification_logs` table |

---

## 16. Controller Complexity Analysis

### Top 10 Controllers by Complexity

| Controller | Lines | Methods | Services Called | Models Touched | Direct DB Queries | Refactor Risk |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `BookingController` | 1,826 | 28 | 6 | 9 | Yes (`DB::table`, `DB::transaction`) | **CRITICAL** |
| `ScreenController` | 1,065 | 16 | 4 | 7 | Yes (`DB::raw`, `DB::select`) | **HIGH** |
| `ScreenProviderPortalController` | 848 | 14 | 3 | 6 | Yes (`DB::raw`) | **HIGH** |
| `DashBoardController` | 472 | 8 | 1 | 8 | Yes (Heavy aggregation SQL) | **MEDIUM** |
| `ReportController` | 410 | 11 | 2 | 4 | No (Clean service delegation) | **LOW** |
| `AdminPaymentController` | 382 | 7 | 3 | 5 | Yes (`DB::transaction`) | **CRITICAL** |
| `AdminScreenProviderController` | 361 | 9 | 2 | 4 | Yes | **HIGH** |
| `PartnerBookingController` | 341 | 8 | 2 | 5 | Yes | **HIGH** |
| `PartnerController` | 333 | 7 | 2 | 4 | Yes | **HIGH** |
| `BlogController` | 300 | 9 | 0 | 4 | No (Standard Eloquent) | **LOW** |

---

## 17. Service Complexity Analysis

### Top 10 Services by Complexity

| Service | Lines | Public Methods | Constructor Dependencies | Models Accessed | Direct DB / Tx | Refactor Risk |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `BudgetRecommendationService` | 1,200 | 12 | 5 | 8 | Yes | **HIGH** |
| `ReportService` | 780 | 14 | 5 | 9 | Yes (Raw SQL aggregation) | **MEDIUM** |
| `ManualBookingService` | 647 | 10 | 7 | 8 | Yes (`DB::transaction`, `lockForUpdate`) | **CRITICAL** |
| `BookingCapacityService` | 580 | 8 | 2 | 5 | Yes (Line-sweep algorithm) | **CRITICAL** |
| `CampaignPricingService` | 510 | 6 | 7 | 6 | Yes | **CRITICAL** |
| `PricingRuleEngine` | 490 | 8 | 1 | 5 | No | **HIGH** |
| `SettlementService` | 369 | 6 | 0 | 5 | Yes (`DB::transaction`, `lockForUpdate`) | **CRITICAL** |
| `ScreenProviderService` | 360 | 9 | 2 | 4 | Yes | **HIGH** |
| `ExchangeRateService` | 317 | 6 | 0 | 1 | No (External HTTP API) | **HIGH** |
| `PaymentService` | 215 | 6 | 2 | 3 | Yes (`DB::transaction`, Razorpay SDK) | **CRITICAL** |

---

## 18. Duplicate Logic Findings

1. **Pricing Preview Calculation:**
   - `CampaignPricingService::calculateCampaignPreview` vs `PricingEngine::calculatePreview`.
   - Risk: **CRITICAL**. Discrepancies between public preview and checkout pricing.
2. **Booking Creation:**
   - `BookingController@storeCampaign` vs `ManualBookingService::createCampaignBooking`.
   - Both implement nearly identical 150-line blocks calculating per-screen shares, snapshotting owner details, and recording revenue split percentages.
3. **Availability & Conflict Verification:**
   - `BookingCapacityService::checkEligibility` vs `BookingController@checkConflicts` vs `ConflictDetectionService::validateBookingConflicts`.
   - Risk: **CRITICAL**. Three entry points checking availability with different levels of precision.

---

## 19. Infrastructure Leakage Findings

| Leaked Infrastructure | Location | Nature of Coupling | Refactoring Remedy |
| :--- | :--- | :--- | :--- |
| **Razorpay SDK** | `PaymentService.php:L24` | Directly instantiates `new Api($key, $secret)` in constructor. | Extract `RazorpayPaymentAdapter` implementing `PaymentGatewayInterface`. |
| **Google Tokeninfo Endpoint**| `GoogleAuthController.php:L30` | Directly invokes `Http::get('oauth2.googleapis.com/tokeninfo')` in controller. | Extract `GoogleOAuthAdapter` implementing `OAuthVerifierInterface`. |
| **ExchangeRate-API Endpoint**| `ExchangeRateService.php:L23` | Hardcoded free-tier URL called via `Http::get()`. | Extract `ExchangeRateApiAdapter` implementing `ExchangeRateProviderInterface`. |
| **PHP GD & Native File IO** | `MediaHelper.php:L43` | Calls `imagecreatefromstring`, `imagewebp`, `copy`, `mkdir` directly. | Extract `LocalMediaStorageAdapter` implementing `MediaStorageInterface`. |

---

## 20. Authorization & Ownership Dependency Findings

1. **Screen Provider Ownership:**
   - Enforced procedurally inside `ScreenProviderPortalController` via helper method `$this->getAuthenticatedProvider($request)`.
   - All queries manually append `->where('screen_provider_id', $provider->id)`.
2. **Partner Client Ownership:**
   - Declaratively protected via `PartnerBookingPolicy` checking `$client->created_by_partner_id === $partner->id`.
3. **Asset Governance:**
   - Declaratively protected via `AssetPolicy` checking `$asset->screen_provider_id === $user->screen_provider_id` for screen providers, or allowing full access to admins.

---

## 21. Test Coverage Mapping

From the 344 automated tests executed in Phase 0.1:
- **Pricing Domain:** 85 tests (`MinutePricingEngineTest`, `PricingRuleEngineTest`, `PricingEngineApiTest`, `MinuteFullRegressionTest`). **Coverage: STRONG**.
- **Availability & Concurrency:** 62 tests (`BookingConcurrencyTest`, `TimestampAvailabilityEngineTest`, `UnifiedBookingEligibilityTest`). **Coverage: STRONG**.
- **Revenue Splits & Settlements:** 44 tests (`RevenueSplitResolverServiceTest`, `ScreenDurationSplitOverrideTest`, `AdPartnerCommissionSettlementTest`). **Coverage: STRONG**.
- **Payment Verification:** 8 tests (`FinanceApiTest`). **Coverage: MEDIUM** (Requires additional mock fixtures for Razorpay error codes).
- **Creative Uploads:** 4 tests (`PartnerBookingPlatformTest`). **Coverage: WEAK** (Multipart video file validation needs expansion).

---

## 22. Target Architecture Mapping

| Current Component | Current Layer | Target Domain | Target Layer | Migration Action |
| :--- | :--- | :--- | :--- | :--- |
| `RevenueSplitResolverService` | Service | Settlement | Domain / Services | Move & Namespace to `Domain\Settlement\Services` |
| `PricingRuleEngine` | Service | Pricing | Domain / Services | Move & Namespace to `Domain\Pricing\Services` |
| `CampaignPricingService` | Service | Pricing | Domain / Services | Move & Expand in `Domain\Pricing\Services` |
| `BookingCapacityService` | Service | Availability | Domain / Services | Move & Namespace to `Domain\Availability\Services` |
| `ManualBookingService` | Service | Booking | Application / Use Case | Extract to `Services\Booking\CreateBookingUseCase` |
| `PaymentService` | Service | Payment | Application & Infra | Split: UseCase in `Services\Payment`, Adapter in `Infrastructure\Payments` |
| `MediaHelper` | Helper | Storage | Infrastructure | Encapsulate in `Infrastructure\Storage\LocalMediaStorageAdapter` |
| `ExchangeRateService` | Service | Currency | Infrastructure | Move HTTP calls to `Infrastructure\ExchangeRates\ExchangeRateApiAdapter` |

---

## 23. Critical Refactoring Candidates

1. **`PricingEngine.php`:** Repoint `calculatePreview()` to delegate to `CampaignPricingService` to guarantee that `/api/v2/pricing/calculate` produces identical mathematical results to `/api/campaigns/calculate`.
2. **`BookingController.php` (1,826 lines):** Extract inline booking creation logic into `CreateCampaignBookingUseCase` shared with `ManualBookingService`.
3. **`PaymentService.php`:** Extract direct Razorpay SDK instantiation into `PaymentGatewayInterface` and `RazorpayPaymentAdapter`.

---

## 24. Dependency Risks

1. **Pessimistic Lock Dependency:** `InventoryService::lockScreensForBooking` and `Screen::lockForUpdate()` must not be unbundled from the outer `DB::transaction()` during use-case extraction. Moving locks outside the transaction will cause concurrent double-booking vulnerabilities.
2. **Snapshot Immutability Dependency:** Provider settlements in `SettlementService` depend completely on the fact that `booking_screens` split percentages are never modified. Any code touchpoint that modifies historic `booking_screens` is a financial integrity hazard.
3. **Paise Conversion Dependency:** Razorpay amounts are strictly calculated in paise (`round($booking->final_amount * 100)`). Any floating-point distortion introduced by an adapter refactor will cause Razorpay signature rejection.

---

## 25. Phase 0.3 Inputs

Phase 0.2 has established:
1. `docs/audit/service-dependency-map.json`: All 52 services with callers, dependencies, and risks.
2. `docs/audit/controller-service-map.json`: All 69 controllers with method-level service and database touchpoints.
3. `docs/audit/business-rule-ownership.json`: Authoritative ownership and duplication matrix across 12 business rules.
4. `docs/audit/database-touchpoint-map.json`: 259 model and database access touchpoints.
5. `docs/audit/external-integration-map.json`: Full integration specifications for Razorpay, Google OAuth, ExchangeRate, Storage, and Email.
6. `docs/audit/transaction-boundary-map.json`: 8 explicit transaction boundaries with lock requirements.

These artifacts will serve as the exact input specifications for **Phase 0.3 (Golden Regression Suite & Contract Hardening)**.

---

## 26. Items Explicitly Not Changed

- **Production Code Modified:** **0 lines**.
- **Controllers Modified:** **0**.
- **Services Modified:** **0**.
- **Repositories Modified:** **0**.
- **Models Modified:** **0**.
- **Routes / Middleware Modified:** **0**.
- **Database Migrations / Schema Modified:** **0**.
- **Automated Tests Modified:** **0**.
- **Phase 0.1 Artifacts Preserved:** **100% intact**.

---

## 27. Answers to the 20 Critical Architectural Questions

### 1. Where is the actual canonical pricing logic today?
**Answer:** In `app/Services/CampaignPricingService.php` (`calculateCampaignPreview()` and `validateCampaignPricing()`). It orchestrates `PricingRuleEngine`, `DailyPricingCalculator`, `ScreenPricingResolver`, `ReferralPricingCalculator`, `FirstBookingDiscountPricingCalculator`, and `LoyaltyService`.

### 2. Are there multiple pricing engines?
**Answer:** **YES.** A second, divergent engine exists in `app/Services/PricingEngine.php` (`calculatePreview()`). It is invoked by `PricingEngineController@calculate` and `BookingEngineController@store`. It performs hardcoded day divisions (`/30`, `/7`, `*24`, `*1440`) and a flat 10% 30-day discount, ignoring database rules despite injecting `CampaignPricingService`.

### 3. Where is availability actually enforced?
**Answer:** In `app/Services/BookingCapacityService.php` (`checkEligibility()`, `validateScreenDailyCapacity()`, `validateFleetCapacity()`).

### 4. Are availability checks and booking writes using the same business rule?
**Answer:** **PARTIALLY.** In `BookingController@storeCampaign` and `ManualBookingService@createCampaignBooking`, `BookingCapacityService::checkEligibility` is used. However, in `BookingController@checkConflicts`, a divergent query calls `InventoryService::getAvailableFleet()` directly; and in `Api\BookingEngineController@store`, it calls `ConflictDetectionService::validateBookingConflicts()`.

### 5. Where is the final booking authority?
**Answer:** 
- For web/customer bookings: `BookingController@storeCampaign`.
- For partner and admin sales: `ManualBookingService::createCampaignBooking`.
- For v2 bookings: `Api\BookingEngineController@store`.

### 6. Where does payment verification mutate booking state?
**Answer:** In `app/Services/PaymentService.php` (`verifyAndConfirmPayment()`, lines 94-160). Inside `DB::transaction()`, `Payment::where('razorpay_order_id', ...)` updates `payment_status = 'paid'`, and `$booking->update(['status' => Booking::STATUS_PENDING_APPROVAL])`.

### 7. Where is provider revenue calculated?
**Answer:** In `app/Services/RevenueSplitResolverService.php` (`resolveEffectiveSplit()`), following a 5-tier precedence hierarchy (Direct Illuvision > Duration Override > Screen Override > Provider Split > System Default 10/90).

### 8. Where is partner commission calculated?
**Answer:** In `app/Services/ReferralTrackingService.php` (`createReferralTracking()`, lines 25-95), triggered by `PaymentService::verifyAndConfirmPayment()`.

### 9. Where are financial transactions persisted?
**Answer:** In tables: `payments`, `booking_screens` (immutable split snapshots), `partner_commissions`, `screen_provider_settlements`, `refunds`.

### 10. Where are transaction boundaries defined?
**Answer:** In 8 primary `DB::transaction()` closures across `ManualBookingService`, `BookingController`, `BookingEngineController`, `PaymentService`, `CommissionService`, and `SettlementService`.

### 11. Which services directly access Eloquent models?
**Answer:** 48 of the 52 services directly call Eloquent static methods (`Screen::`, `Booking::`, `User::`, etc.), completely bypassing the repository layer.

### 12. Which services bypass repositories?
**Answer:** All services except `AssetService` (which injects `AssetRepositoryInterface`) and `BookingWorkflowService` (which references `BookingRepositoryInterface`).

### 13. Which controllers contain business logic?
**Answer:** 
- `BookingController` (1,826 lines - pricing snapshots, capacity conversions, DB inserts, activity logs)
- `ScreenController` (1,065 lines - queries, file compressions, split overrides)
- `ScreenProviderPortalController` (848 lines - revenue aggregation, ownership enforcement)
- `DashBoardController` (472 lines - raw aggregation SQL)

### 14. Which services contain HTTP/controller concerns?
**Answer:** 
- `MediaService` (accepts `Illuminate\Http\UploadedFile`, formats HTTP response arrays)
- `AssetDiscoveryFilterService` (parses `$request->input()`)
- `BudgetRecommendationService` (parses request parameter formats)

### 15. Which classes directly call external SDKs?
**Answer:** 
- `PaymentService` directly instantiates `new Razorpay\Api\Api($key, $secret)`.
- `GoogleAuthController` directly calls `Http::get('https://oauth2.googleapis.com/tokeninfo')`.
- `ExchangeRateService` directly calls `Http::get('https://api.exchangerate-api.com/v4/latest/INR')`.

### 16. Which classes directly access filesystem/storage?
**Answer:** `app/Helpers/MediaHelper.php` uses native PHP functions (`mkdir`, `copy`, `imagewebp`, `file_put_contents`, `storage_path('app/public')`).

### 17. Are there circular dependencies?
**Answer:** No constructor cycles exist. However, a **soft runtime dependency cycle** exists between `CampaignPricingService` (which injects `LoyaltyService`) and `LoyaltyService` (which resolves `CampaignPricingService` via `app(CampaignPricingService::class)`).

### 18. Are there duplicated business rules?
**Answer:** **YES.** Pricing calculations (`CampaignPricingService` vs `PricingEngine`), availability checks (`BookingCapacityService` vs `ConflictDetectionService` vs `InventoryService`), and booking creation (`ManualBookingService` vs `BookingController` vs `BookingEngineController`).

### 19. Which components are safe candidates for future extraction?
**Answer:** 
1. `RevenueSplitResolverService` (isolated, pure logic, zero DB writes).
2. `PricingRuleEngine` (pure duration conversion & rule lookup).
3. `Infrastructure/Payments/RazorpayPaymentAdapter` (wrapping `PaymentService`'s Razorpay calls behind `PaymentGatewayInterface`).
4. `Infrastructure/Storage/LocalMediaStorageAdapter` (wrapping `MediaHelper`).

### 20. Which components must remain untouched until stronger regression coverage exists?
**Answer:** 
1. `BookingController@storeCampaign` (1,826 lines).
2. `PaymentService::verifyAndConfirmPayment` (critical financial transaction).
3. `SettlementService` and `booking_screens` snapshot schemas.

---
*Phase 0.2 Audit Completed by Antigravity Senior Backend & Laravel Migration Architect.*
