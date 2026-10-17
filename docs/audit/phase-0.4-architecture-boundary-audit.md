# PHASE 0.4 — ARCHITECTURAL BOUNDARY & DOMAIN OWNERSHIP BASELINE AUDIT REPORT

## Executive Summary

* **Project**: Illuvision Advertising Marketplace Core Backend
* **Phase**: Phase 0.4 — Architectural Boundary & Domain Ownership Baseline
* **Phase Status**: `COMPLETE`
* **Architectural Principle**: *"Refactor the implementation, not the external contract."*

### Scope & Mission
Phase 0.4 establishes the **target architectural boundary map** and **domain ownership blueprint** for the Illuvision backend before any production refactoring begins in Phase 0.5. Building upon the verified API route baseline (Phase 0.1), the service dependency map (Phase 0.2), and the hardened golden regression test suite (Phase 0.3), this audit defines:
1. Where each current responsibility belongs in the target domain-driven architecture.
2. The exact strangler-pattern extraction plan for monolithic controllers and services.
3. The dependency-aware, risk-prioritized migration sequence that guarantees zero breaking changes, zero financial regressions, and zero transaction corruption.

### Major Findings
1. **Domain Cohesion**: The codebase maps cleanly into 12 core marketplace business domains: `Campaign`, `Media`, `Asset`, `Provider`, `Partner`, `Pricing`, `Availability`, `Booking`, `Payment`, `Settlement`, `Reporting`, and `Creative`.
2. **Controller Monoliths**: Critical commercial controllers (notably `BookingController` at 1,826 LOC and `ScreenController` at 1,065 LOC) currently house extensive domain rules, direct Eloquent mutations, pricing snapshots, and notification side effects. These can be surgically extracted into typed Application Use Cases without altering existing HTTP route signatures or response payloads.
3. **Financial Segregation**: 9 key financial calculations (subtotal, discounts, referral rewards, payable amount, provider share, platform share, partner commission, paise conversion, and net settlement) are currently split across 4 services and several controller methods. Establishing `CampaignPricingEngine` as the single calculation source of truth resolves quote-vs-checkout variance.
4. **Dependency Cycles**: One class-level runtime cycle (`CampaignPricingService` <-> `LoyaltyService`) and cross-table direct writes between `Payment` and `Booking` were identified and isolated with targeted decoupling strategies.
5. **Regression Protection**: All target migration boundaries are pinned by the 34 golden regression contract tests in `tests/Feature/Golden/`, ensuring every subsequent migration step has immediate verification gates.

---

## 2. Current Architecture Summary

The existing system operates as a standard monolithic Laravel 11 Active Record application:
* **Presentation / HTTP**: Controllers (`app/Http/Controllers`) frequently act as orchestrators, query builders, and business rule evaluators simultaneously.
* **Domain / Business Logic**: Distributed across `app/Services/` (45 services), model query scopes and mutators, and inline controller methods.
* **Persistence**: Direct Eloquent Active Record models (54 models) utilized directly across controllers and services, with only 2 repositories currently existing.
* **External Integrations**: Third-party SDKs (Razorpay, Google OAuth, Currency API) are instantiated and invoked directly within application services and controllers rather than behind formal adapter interfaces.

---

## 3. Target Domain Map

The canonical Marketplace domain map comprises 12 distinct business domains:

| Domain | Primary Purpose & Capability | Core Entities |
|---|---|---|
| **Campaign** | Campaign planning, advertiser categories, and campaign lifetime settings | `AdvertisementCategory`, `HoldBooking` |
| **Media** | Ad creative uploads, aspect-ratio/resolution validation, review & approval | `BookingMedia` |
| **Asset** | Billboard & digital display inventory, specs, location hierarchy, operational states | `Screen`, `ScreenCategory`, `ScreenPrice`, `ScreenOperatingHour` |
| **Provider** | Screen owners/landlords, banking details, verification, provider portal | `ScreenProvider`, `ProviderKycDocument`, `ProviderBankAccount` |
| **Partner** | Agency & referral partners, partner tiers, partner team management | `Partner`, `PartnerTier`, `PartnerTeamMember` |
| **Pricing** | Rate calculation, duration discounts, coupons, surge multipliers, quote generation | `ScreenPrice`, `ScreenDiscountRule`, `Coupon` |
| **Availability**| Slot capacity verification, loop duration checks, conflict detection | `BookingScreen`, `ScreenOperatingHour`, `HoldBooking` |
| **Booking** | Campaign booking lifecycle, line items, reservation, reference generation | `Booking`, `BookingScreen`, `BookingHistory` |
| **Payment** | Gateway integration, order creation, HMAC signature verification, receipts | `Payment`, `PaymentRefund`, `PaymentWebhookLog` |
| **Settlement** | Financial reconciliation, provider revenue split (80/20), partner payouts | `ProviderSettlement`, `PartnerCommission`, `CommissionPayout` |
| **Reporting** | Analytics aggregation, occupancy metrics, campaign proof-of-play | Read models / aggregated database views |
| **Creative** | Dynamic creative templates, layout specifications, rendering assets | `CreativeTemplate`, `CreativeLayer` |

---

## 4. Domain Ownership (Current vs Target)

```
CURRENT ARCHITECTURE (Scattered Ownership)
  BookingController (1,826 LOC) ──────┐
  ScreenController (1,065 LOC)  ──────┤
  CampaignPricingService ─────────────┼───► Entangled Active Record Models
  BookingCapacityService ─────────────┤
  PaymentService (Direct Razorpay) ───┘

TARGET ARCHITECTURE (Strict Layered Boundaries)
  Http/Controllers (Thinned, HTTP orchestration only)
        │
        ▼
  Application/UseCases (e.g. CreateCampaignBooking, VerifyPayment)
        │
        ▼
  Domain/<Domain> (Pure domain models, calculation engines, business invariants)
        │
        ▼
  Infrastructure/ (Payment gateway adapters, storage adapters, persistence repositories)
```

---

## 5. Controller Extraction Plan

Full details cataloged in [`docs/audit/controller-extraction-map.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/controller-extraction-map.json).

| Controller | Current LOC | Primary Responsibilities Extracted | Target Application Use Case / Service |
|---|---|---|---|
| `BookingController` | 1,826 | Campaign checkout orchestration, inventory reservation, pricing snapshotting, payment order dispatch | `Application\Booking\UseCases\CreateCampaignBookingUseCase`, `CheckCampaignAvailabilityUseCase` |
| `ScreenController` | 1,065 | Screen inventory CRUD, operating hours validation, spatial search, media spec mapping | `Application\Asset\UseCases\ManageScreenUseCase`, `QueryAvailableScreensUseCase` |
| `ScreenProviderPortalController`| 485 | Provider asset management, revenue dashboard, earnings calculation | `Application\Provider\UseCases\GetProviderDashboardUseCase` |
| `PaymentController` | 165 | Razorpay order creation, payment signature verification | `Application\Payment\UseCases\CreatePaymentOrderUseCase`, `VerifyPaymentUseCase` |
| `PartnerBookingController` | 342 | Partner client booking, commission tier snapshotting | `Application\Booking\UseCases\CreatePartnerBookingUseCase` |
| `AdminManualBookingController` | 280 | Admin manual booking, commercial overrides, forced slot allocations | `Application\Booking\UseCases\CreateAdminManualBookingUseCase` |
| `BookingEngineController` | 139 | Ad-hoc availability inspection and booking quotation | `Application\Availability\UseCases\InspectAvailabilityUseCase` |
| `GoogleAuthController` | 84 | OAuth code exchange, social profile sync | `Infrastructure\Auth\GoogleOAuthAdapter` |

---

## 6. Service Migration Plan

Full details cataloged in [`docs/audit/service-migration-map.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/service-migration-map.json).

| Service | Current Role | Target Layer & Domain | Recommended Action |
|---|---|---|---|
| `PaymentService` | Coupled Razorpay gateway & DB | `Infrastructure\Payments\RazorpayPaymentGatewayAdapter` | Wrap behind `PaymentGatewayInterface` (Step 1) |
| `CampaignPricingService`| Commercial quote calculation | `Domain\Pricing\CampaignPricingEngine` | Pure calculation extraction, decouple dynamic loyalty call (Step 3) |
| `PricingEngine` | Dynamic multiplier evaluator | `Application\Pricing\Adapters\LegacyPricingEngineAdapter` | Wrap legacy engine for V2 compatibility (Step 4) |
| `BookingCapacityService`| Screen loop capacity checks | `Domain\Availability\CapacityCalculationEngine` | Extract pure math from SQL line sweep (Step 5) |
| `InventoryService` | Screen availability calendar | `Domain\Availability\InventoryService` | Consolidate with Capacity Engine (Step 5) |
| `RevenueDistributionService`| 80/20 provider/platform split | `Domain\Settlement\RevenueDistributionEngine` | Move to pure domain calculation (Step 6) |
| `SettlementService` | Provider settlement payouts | `Domain\Settlement\SettlementEngine` | Encapsulate balance update into Provider domain (Step 6) |
| `CommissionService` | Partner commission calculator | `Domain\Partner\CommissionEngine` | Domain service under Partner (Step 6) |
| `ExchangeRateService` | Currency API HTTP client | `Infrastructure\ExchangeRates\ExchangeRateApiClient` | Move behind `ExchangeRateProviderInterface` (Step 2) |
| `MediaHelper` | File uploads & GD resizing | `Infrastructure\Storage\MediaStorageService` | Convert static methods into injectable service (Step 2) |

---

## 7. Database Domain Ownership

Full details cataloged in [`docs/audit/database-domain-ownership.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/database-domain-ownership.json).

* **Booking Domain**: `bookings`, `booking_screens`, `booking_histories`, `hold_bookings`.
* **Asset Domain**: `screens`, `screen_categories`, `screen_operating_hours`, `screen_images`, `screen_pricing`.
* **Payment Domain**: `payments`, `payment_refunds`, `payment_webhook_logs`.
* **Provider Domain**: `screen_providers`, `provider_kyc_documents`, `provider_bank_accounts`.
* **Partner Domain**: `partners`, `partner_tiers`, `partner_commissions`.
* **Settlement Domain**: `provider_settlements`, `commission_payouts`.
* **Media Domain**: `booking_media`.

---

## 8. Financial Ownership

Full details cataloged in [`docs/audit/financial-domain-boundary-map.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/financial-domain-boundary-map.json).

* **Subtotal / Duration Discount**: Owned by `Domain\Pricing\CampaignPricingEngine`.
* **Referral / Coupon Discount**: Owned by `Domain\Pricing\CampaignPricingEngine`.
* **Final Payable Invoice**: Owned by `Domain\Pricing\CampaignPricingEngine` and snapshotted immutably into `bookings`.
* **Payment Order Amount & Paise Conversion**: Owned by `Domain\Payment\PaymentService` (`amount * 100`).
* **Provider Revenue Share (80%)**: Owned by `Domain\Settlement\RevenueDistributionEngine`.
* **Platform Fee (20%)**: Owned by `Domain\Settlement\RevenueDistributionEngine`.
* **Partner Commission (5% - 15%)**: Owned by `Domain\Partner\CommissionEngine`.
* **Settlement Net Payout**: Owned by `Domain\Settlement\SettlementEngine`.

---

## 9. External Integrations & Adapter Boundaries

Full details cataloged in [`docs/audit/external-boundary-map.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/external-boundary-map.json).

```
Application Layer
       │
       ▼
Domain Interface (e.g. PaymentGatewayInterface, ExchangeRateInterface)
       │
       ▼
Infrastructure Adapter (e.g. RazorpayPaymentGatewayAdapter, ExchangeRateApiClient)
       │
       ▼
External Third-Party SDK / Network API (Razorpay, Google OAuth, Currency API)
```

---

## 10. Transaction Boundaries & Concurrency

1. **Payment Verification**: Cryptographic HMAC verification is performed offline first. Only upon signature match does a database transaction open to execute `SELECT ... FOR UPDATE` on `payments` and `bookings`, guaranteeing zero gateway network timeouts within database locks.
2. **Booking Slot Allocation**: `CreateCampaignBookingUseCase` executes inside an atomic database transaction. Screen loop capacity is locked and validated before inserting rows into `bookings` and `booking_screens`.
3. **Settlement Generation**: Payout aggregation locks unsettled bookings using `FOR UPDATE`, generates the `provider_settlements` record, and updates booking settlement flags in a single commit boundary.

---

## 11. Domain Dependency Graph & Cycle Analysis

Full details cataloged in [`docs/audit/domain-dependency-graph.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/domain-dependency-graph.json).

* **Class Cycle**: `CampaignPricingService` constructor injects `LoyaltyService`; `LoyaltyService` dynamically calls `app(CampaignPricingService::class)`.
  * *Resolution*: Decouple by passing customer loyalty points as a value parameter into `CampaignPricingEngine`.
* **Domain Cycle**: `Booking` <-> `Availability`.
  * *Resolution*: Availability reads an inventory projection/read-model rather than mutating Booking entities directly.
* **Database Cycles**: `Payment` directly writing `bookings` table; `Settlement` directly writing `screen_providers.balance`.
  * *Resolution*: Replace direct cross-domain table updates with explicit Domain Events (`PaymentReceived`, `SettlementApproved`) or application use-case orchestration.

---

## 12. Architecture Risk Assessment

| Risk Level | Risk Description | Evidence | Mitigation Strategy |
|---|---|---|---|
| **CRITICAL** | Financial calculation drift during checkout | Multiple pricing services exist (`CampaignPricingService` vs `PricingEngine`) | Canonicalize `CampaignPricingEngine`; pin with penny-accurate golden tests in `GoldenPricingContractTest`. |
| **CRITICAL** | Double-booking race condition under concurrency | High-frequency concurrent requests targeting identical digital display slots | Atomic transaction with pessimistic row lock on slot reservation. |
| **HIGH** | External gateway timeouts blocking DB transactions | Network calls to Razorpay if placed inside `DB::transaction()` | Separate external gateway calls strictly outside database transaction blocks. |
| **MEDIUM** | Breaking client response contracts during controller thinning | Controllers returning custom JSON structures rather than API Resources | Verify every endpoint against `routes-baseline.json` and `Golden` feature tests. |
| **LOW** | Static storage and currency utility migration | `MediaHelper` and `ExchangeRateService` have zero state invariants | Straightforward infrastructure adapter extraction. |

---

## 13. Architecture Migration Order (Phase 0.5+)

Full details cataloged in [`docs/audit/architecture-migration-order.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/architecture-migration-order.json).

```
Step 1: Payment Gateway Adapter (Infrastructure\Payments\RazorpayPaymentGatewayAdapter)
   ↓
Step 2: External Infrastructure Adapters (Exchange rates, storage, email)
   ↓
Step 3: Core Pure Pricing Engine (Domain\Pricing\CampaignPricingEngine)
   ↓
Step 4: Legacy Pricing Rule Adapter (Application\Pricing\Adapters\LegacyPricingEngineAdapter)
   ↓
Step 5: Availability & Capacity Engine (Domain\Availability\CapacityCalculationEngine)
   ↓
Step 6: Financial Settlement Engine (Domain\Settlement\RevenueDistributionEngine)
   ↓
Step 7: Application Booking Use Cases (Application\Booking\UseCases\)
   ↓
Step 8: Controller Thinning & HTTP Sanitization (BookingController, ScreenController)
```

---

## 14. Golden Test Coverage

Every migration step is safeguarded by dedicated tests created and verified in Phase 0.3:
* **Pricing Engine**: `tests/Feature/Golden/GoldenPricingContractTest.php` & `tests/Unit/Services/CampaignPricingServiceTest.php`
* **Availability**: `tests/Feature/Golden/GoldenAvailabilityContractTest.php`
* **Booking Creation**: `tests/Feature/Golden/GoldenBookingContractTest.php`
* **Payment & HMAC Verification**: `tests/Feature/Golden/GoldenPaymentIntegrationTest.php`
* **Financial Calculations**: `tests/Feature/Golden/GoldenFinancialContractTest.php`

---

## 15. Open Architectural Decisions Required

Documented in [`docs/audit/architecture-decisions.md`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/architecture-decisions.md):
1. **Decision Required**: V2 pricing dynamic multipliers vs canonical V1 pricing engine convergence.
2. **Decision Required**: Availability calculation granularity (calendar days vs continuous slot time-sweeping).
3. **Approved**: Multi-gateway driver manager architecture for payments.
4. **Approved**: Permanent backward compatibility for all 385 routes baseline.
5. **Approved**: Decoupling `LoyaltyService` dynamic container cycle via pure value objects.

---

## 16. Production Changes Verification

In strict accordance with Phase 0.4 requirements:
* **Production behavior changed**: `NO`
* **Production architecture changed**: `NO`
* **Database schema changed**: `NO`
* **API contract changed**: `NO`

*All changes in Phase 0.4 are strictly confined to architectural blueprints, maps, and decision records under `docs/audit/`.*
