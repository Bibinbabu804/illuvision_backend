# Phase 0.6 — Pricing Architecture Diff

## Overview

Phase 0.6 establishes the canonical Pricing Domain boundary (`App\Domain\Pricing`) for the Illuvision Advertising Marketplace backend while isolating legacy V2 multi-asset pricing behind an application adapter (`App\Application\Pricing\Adapters\LegacyPricingEngineAdapter`).

---

## 1. Architectural Topology: Before vs After

### Before Phase 0.6

```text
[HTTP Clients]
      │
      ├───────────────────────────────┬───────────────────────────────┐
      │                               │                               │
      ▼                               ▼                               ▼
POST /api/campaigns/calculate   POST /api/bookings/campaign    POST /api/v2/pricing/calculate
      │                               │                               │
      ▼                               ▼                               ▼
BookingController               BookingController            PricingEngineController
      │                               │                               │
      ▼                               ▼                               │
CampaignPricingService          CampaignPricingService                 │
      │                               │                               ▼
      ├───────────────────────────────┤                          PricingEngine
      │                               │                               │
      ▼                               ▼                               ▼
PricingRuleEngine, DailyPricingCalculator, ...                    Legacy Algorithm
      │                                                               │
      ▼                                                               ▼
Mixed DB Queries / Eloquent Models                               Direct Screen Query
```

### After Phase 0.6

```text
[HTTP Clients]
      │
      ├───────────────────────────────┬───────────────────────────────┐
      │                               │                               │
      ▼                               ▼                               ▼
POST /api/campaigns/calculate   POST /api/bookings/campaign    POST /api/v2/pricing/calculate
      │                               │                               │
      ▼                               ▼                               ▼
BookingController               BookingController            PricingEngineController
      │                               │                               │
      ▼                               ▼                               ▼
CalculateCampaignPriceUseCase   CampaignPricingService        LegacyPricingEngineAdapter
      │                               │                               │
      │                               ▼                               │
      │                  PricingEngineInterface                       │
      │                               │                               │
      └───────────────────────►       │                               │
                                      ▼                               ▼
                       Domain\Pricing\CampaignPricingEngine     Services\PricingEngine
                                      │                         (Legacy Isolated Path)
                                      ▼
             [Pure Domain Calculators & Resolvers]
             ├── DailyPricingCalculator
             ├── PricingRuleEngine
             ├── ScreenPricingResolver
             ├── FirstBookingDiscountPricingCalculator
             ├── ReferralPricingCalculator
             └── SettingsService / LoyaltyService
```

---

## 2. Key Architecture Refactorings

### 2.1 Domain Boundary: `PricingEngineInterface` & `CampaignPricingEngine`
- **Location**: `App\Domain\Pricing\Contracts\PricingEngineInterface` & `App\Domain\Pricing\CampaignPricingEngine`
- **Responsibility**: Pure domain pricing calculation, duration conversion, and minimum duration enforcement.
- **Inversion of Control**: Bound in `AppServiceProvider`:
  ```php
  $this->app->bind(PricingEngineInterface::class, CampaignPricingEngine::class);
  ```

### 2.2 Application Use Case: `CalculateCampaignPriceUseCase`
- **Location**: `App\Application\Pricing\UseCases\CalculateCampaignPriceUseCase`
- **Responsibility**: Application service entry point for quotation and preview requests. Decoupled from `CampaignPricingService` and depends strictly on `PricingEngineInterface`.

### 2.3 Legacy Adapter: `LegacyPricingEngineAdapter`
- **Location**: `App\Application\Pricing\Adapters\LegacyPricingEngineAdapter`
- **Responsibility**: Wraps `App\Services\PricingEngine` for `/api/v2/pricing/calculate`.
- **Zero Duplication**: Delegates directly to the existing legacy algorithm without duplication.

### 2.4 Backward Compatibility Facade: `CampaignPricingService`
- Remains in place to serve existing callers (e.g. `BookingController@store`, `ManualBookingService`, `BudgetRecommendationService`).
- Internal methods `calculateCampaignPreview()`, `validateMinimumDuration()`, and `convertMinDurationToDays()` delegate transparently to `PricingEngineInterface`.

---

## 3. Contract & Commercial Invariants Preserved

1. **Numeric Precision**: Decimal math and round-to-2-decimals at boundary preserved exactly.
2. **Golden Coverage**: All 34 golden regression tests pass with 100% fidelity.
3. **HTTP Schemas**: Request payloads, validation rules, status codes (200, 422, 401, 403), and response envelopes remain identical.
