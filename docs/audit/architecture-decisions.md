# Target Architecture Decision Records (ADRs) & Open Architectural Questions

## Illuvision Core Backend — Phase 0.4 Baseline

This document records the architectural decisions, architectural trade-offs, and open decision-required questions formulated during Phase 0.4.

---

### ADR-01: Canonical Pricing Engine Selection

* **Decision**: Establish `CampaignPricingService` (refactored as `App\Domain\Pricing\CampaignPricingEngine`) as the single commercial source of truth for all campaign quotations, booking checkouts, and financial estimates.
* **Evidence**:
  * `CampaignPricingService` contains the full comprehensive commercial formula: base subtotal, duration discounts, referral discounts, coupons, taxes, and customer loyalty point redemptions.
  * In contrast, `PricingEngine` and `PricingRuleEngine` are dynamic multiplier evaluators (e.g., peak-hour surge, category multipliers) that compute ad-hoc factors rather than the final commercial checkout invoice.
* **Alternatives Considered**:
  1. *Merge all 4 pricing services immediately*: High regression risk; would break existing V2 endpoints and dynamic multiplier routes.
  2. *Retain dual pricing systems permanently*: Dangerous financial drift where quoting an estimate in V2 yields a different price than checkout in V1.
* **Reason**: Separating the pure calculation engine (`CampaignPricingEngine`) and wrapping `PricingEngine` as a multiplier adapter allows preserving backward compatibility while guaranteeing invoice consistency.
* **Impact**: All checkout flows (V1, V2, Partner, Manual) will resolve through the same core pricing logic.
* **Future Phase**: Phase 0.5 (Pricing Domain extraction).
* **Status**: `APPROVED ARCHITECTURAL DIRECTION`

---

### ADR-02: Payment Transaction Isolation & Offline HMAC Verification

* **Decision**: Move the Razorpay payment gateway behind a clean `PaymentGatewayInterface` adapter, ensuring that external HTTP API calls are strictly separated from local database transaction boundaries. Signature verification (`verifyPayment`) must execute purely offline via HMAC-SHA256 before opening database locks.
* **Evidence**:
  * In `PaymentService::verifyPayment()`, cryptographic signature verification (`hash_hmac('sha256', ...)`) requires zero external network calls.
  * Running network calls inside database transactions risks row lock exhaustion and connection pool starvation during gateway latency spikes.
* **Alternatives Considered**:
  1. *Leave Razorpay SDK coupled directly in PaymentService*: Prevents unit testing without mock gymnastics, mixes gateway exceptions with domain persistence exceptions.
* **Reason**: Strict infrastructure separation guarantees deterministic offline regression testing (as verified by Phase 0.3's 100% passing golden suite) and protects transaction isolation.
* **Impact**: Zero external network timeouts inside database transactions.
* **Future Phase**: Phase 0.5 (Step 1 of migration sequence).
* **Status**: `APPROVED ARCHITECTURAL DIRECTION`

---

### ADR-03: Booking Orchestration via Dedicated Application Use Cases

* **Decision**: Extract campaign booking orchestration out of `BookingController` (currently 1,826 LOC) into explicit Application Use Cases: `CreateCampaignBookingUseCase`, `CreatePartnerBookingUseCase`, and `CreateAdminManualBookingUseCase`.
* **Evidence**:
  * `BookingController` currently mixes HTTP validation, availability checking, pricing calculation, customer creation, booking line item insertion, discount snapshotting, payment order dispatch, and email notifications in monolithic controller actions.
  * Multiple controllers (`BookingController`, `PartnerBookingController`, `AdminManualBookingController`, `BookingEngineController`) duplicate booking creation steps with subtle inconsistencies.
* **Alternatives Considered**:
  1. *Keep logic in an enlarged `BookingService`*: Perpetuates "God Service" anti-pattern without clear Single Responsibility boundaries.
  2. *Extract directly to Eloquent Model hooks*: Creates hidden side effects and couples persistence with external notifications.
* **Reason**: Dedicated Use Cases define explicit transactional boundaries, accept typed DTOs, and can be called interchangeably from HTTP controllers, queue workers, or CLI commands.
* **Impact**: `BookingController` LOC reduced from 1,826 to under 150 LOC.
* **Future Phase**: Phase 0.6.
* **Status**: `APPROVED ARCHITECTURAL DIRECTION`

---

### ADR-04: Availability & Inventory Capacity Locking Strategy

* **Decision**: Maintain atomic slot reservation using database pessimistic row locking (`SELECT ... FOR UPDATE` on screen slot reservations or Redis distributed mutex) inside the `CreateCampaignBookingUseCase` transaction.
* **Evidence**:
  * Digital billboard loops have finite time slice capacity (e.g. 60 seconds total loop). Overlapping bookings that exceed loop duration represent a physical breach of contract.
  * Phase 0.3 verified that high-concurrency race conditions can produce inventory conflicts if capacity checks are decoupled from write locks.
* **Alternatives Considered**:
  1. *Optimistic locking via version column*: Causes high abort rates during popular advertising campaign drops (e.g., New Year's Eve screens).
  2. *Asynchronous reservation with post-payment reconciliation*: Risks having to refund customers after payment collection.
* **Reason**: Synchronous atomic capacity verification guarantees zero double-bookings.
* **Impact**: Concurrency safety maintained across all booking channels.
* **Future Phase**: Phase 0.6.
* **Status**: `APPROVED ARCHITECTURAL DIRECTION`

---

### ADR-05: Targeted Repository Abstraction (No Boilerplate Everywhere)

* **Decision**: Implement repository interfaces only where domain decoupling and infrastructure swappability provide concrete value (specifically `BookingRepositoryInterface`, `ScreenRepositoryInterface`, and `PaymentRepositoryInterface`). Avoid creating anemic CRUD repositories for static reference entities (e.g., `AdvertisementCategory`, `ScreenCategory`).
* **Evidence**:
  * Previous audits revealed 54 Eloquent models and only 2 repositories. Blanket repository creation across all 54 models would generate hundreds of boilerplate files with zero architectural benefit.
* **Alternatives Considered**:
  1. *Create Repository + Interface for all 54 Eloquent models*: Severe boilerplate overhead and developer friction.
  2. *No repositories; use direct Eloquent everywhere*: Entangles domain logic with Active Record database queries, making unit testing impossible without active MySQL connections.
* **Reason**: Focus repository abstraction strictly on high-churn, business-critical aggregates.
* **Impact**: Clean boundaries for core domains without unnecessary boilerplate.
* **Future Phase**: Phases 0.5 and 0.6.
* **Status**: `APPROVED ARCHITECTURAL DIRECTION`

---

### ADR-06: Commercial Historical Immutability Guarantee

* **Decision**: All financial fields on `Booking`, `BookingScreen`, and `Payment` records become strictly immutable once `payment_status` transitions to `PAID` or `status` transitions to `CONFIRMED`.
* **Evidence**:
  * Recalculating pricing or commissions on past bookings using dynamic modern rates causes financial ledger corruption and audit failure.
  * Invoices and provider revenue calculations require historical snapshots.
* **Alternatives Considered**:
  1. *Dynamically recalculate pricing from current screen rates on report generation*: Causes past month revenue figures to shift when screen base rates change.
* **Reason**: Adherence to double-entry and GAAP financial audit standards.
* **Impact**: Invariant enforced by domain entity guard clauses.
* **Future Phase**: Phase 0.5+.
* **Status**: `APPROVED ARCHITECTURAL DIRECTION`

---

## Open Architectural Questions & Decisions Required

| # | Question | Current Evidence | Affected Components | Proposed Architectural Option | Business Impact | Status |
|---|---|---|---|---|---|---|
| **Q1** | **Should V2 pricing and campaign endpoints remain behaviorally distinct or converge with canonical V1 formulas?** | V2 endpoints utilize `PricingEngine` and `PricingRuleEngine` with dynamic multiplier rules, whereas V1 uses `CampaignPricingService`. | `Api\V2\BookingController`, `CampaignPricingService`, `PricingRuleEngine` | Wrap canonical calculation in a V2 Adapter that applies dynamic multiplier rules before invoice computation. | Eliminates quoting discrepancies across client apps while preserving V2 response format. | `DECISION REQUIRED` |
| **Q2** | **What is the canonical availability algorithm: exact continuous time-sweep or discrete hourly/daily loop occupancy?** | `BookingCapacityService` performs loop-duration checks; `InventoryService` checks date-range calendar overlaps. | `BookingCapacityService`, `InventoryService`, `ConflictDetectionService` | Unify into `CapacityCalculationEngine` that verifies both calendar date availability AND loop second capacity in a single sweep. | Prevents edge cases where date is free but loop is full, or vice versa. | `DECISION REQUIRED` |
| **Q3** | **Should Payment Gateway abstraction support multi-gateway routing (e.g. Stripe/PayU alongside Razorpay)?** | Currently 100% hardcoded to Razorpay SDK in `PaymentService`. | `PaymentService`, `PaymentController`, `payments` table | Design `PaymentGatewayInterface` with driver-based gateway manager (`PaymentGatewayManager`), with Razorpay as default driver. | Prepares platform for international currencies and fallback gateways. | `APPROVED FOR PHASE 0.5` |
| **Q4** | **Which legacy endpoints require permanent backward compatibility vs scheduled sunset?** | Phase 0.1 identified 385 routes, including redundant duplicate routes (`/api/bookings` vs `/api/bookings/campaign`, legacy quotation paths). | Routes in `routes-baseline.json` | Maintain all 385 routes in Phase 0.4 - 1.0 by routing legacy endpoints to the new Application Use Cases. | Zero breaking changes for existing web and mobile frontends. | `APPROVED ARCHITECTURAL DIRECTION` |
| **Q5** | **How should Circular Dependency between LoyaltyService and CampaignPricingService be permanently resolved?** | `CampaignPricingService` injects `LoyaltyService`; `LoyaltyService` dynamically calls `app(CampaignPricingService::class)`. | `CampaignPricingService`, `LoyaltyService` | Refactor pricing engine to be pure: customer loyalty points and discount values are passed in as input arguments rather than fetched internally via container recursion. | Eliminates memory leaks and circular container resolution risks. | `APPROVED FOR PHASE 0.5` |
