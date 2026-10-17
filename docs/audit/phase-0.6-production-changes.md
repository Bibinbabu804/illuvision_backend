# Phase 0.6 — Production Changes Log

## Overview

This document records all production code changes introduced in Phase 0.6 to extract the pricing domain boundary, establish the canonical pricing engine, and isolate legacy pricing paths.

---

### 1. `app/Domain/Pricing/Contracts/PricingEngineInterface.php`
* **Change**: Created interface defining canonical campaign pricing engine contracts: `calculate(array $params): array`, `validateMinimumDuration(array $screenIds, string $startDate, string $endDate, string $dailyStartTime = '00:00', string $dailyEndTime = '23:59'): array`, and `convertMinDurationToDays(int|float $value, string $type): float`.
* **Reason**: Establishes the canonical domain contract for all campaign pricing calculations in accordance with clean architecture boundaries.
* **Behavioral Impact**: None; interface declaration only.
* **Tests**: `tests/Unit/Domain/Pricing/CampaignPricingEngineTest.php`, `tests/Feature/Golden/GoldenPricingRegressionTest.php`.
* **Rollback**: Delete file and remove references.

---

### 2. `app/Domain/Pricing/CampaignPricingEngine.php`
* **Change**: Created pure domain engine implementing `PricingEngineInterface`. Orchestrates `ScreenPricingResolver`, `DailyPricingCalculator`, `ReferralPricingCalculator`, `FirstBookingDiscountPricingCalculator`, `PricingRuleEngine`, `LoyaltyService`, and `SettingsService`.
* **Reason**: Extracts pricing domain logic into the pure domain boundary without framework HTTP or controller coupling.
* **Behavioral Impact**: Preserves 100% mathematical and contract parity with the existing V1 commercial pricing algorithm.
* **Tests**: `tests/Unit/Domain/Pricing/CampaignPricingEngineTest.php`, `tests/Feature/Golden/GoldenPricingRegressionTest.php`.
* **Rollback**: Revert caller delegations back to `CampaignPricingService`.

---

### 3. `app/Application/Pricing/Adapters/LegacyPricingEngineAdapter.php`
* **Change**: Created adapter wrapping `App\Services\PricingEngine` for multi-asset quotation.
* **Reason**: Isolates legacy V2 pricing algorithm without duplicating code, satisfying the "No Duplication Rule" (Section 17).
* **Behavioral Impact**: None; delegates directly to legacy engine.
* **Tests**: `tests/Unit/Application/Pricing/LegacyPricingEngineAdapterTest.php`, `tests/Feature/Golden/GoldenPricingRegressionTest.php`.
* **Rollback**: Remove adapter and inject `PricingEngine` directly in controller.

---

### 4. `app/Application/Pricing/UseCases/CalculateCampaignPriceUseCase.php`
* **Change**: Modified constructor dependency injection from `CampaignPricingService` to `PricingEngineInterface`.
* **Reason**: Adheres to Dependency Inversion Principle; decouples application use cases from concrete services.
* **Behavioral Impact**: None; calls `PricingEngineInterface::calculate()`.
* **Tests**: `tests/Unit/Application/Pricing/CalculateCampaignPriceUseCaseTest.php`, `tests/Feature/Golden/GoldenApiContractRegressionTest.php`.
* **Rollback**: Change injected type back to `CampaignPricingService`.

---

### 5. `app/Providers/AppServiceProvider.php`
* **Change**: Registered singleton/binding:
  ```php
  $this->app->bind(
      \App\Domain\Pricing\Contracts\PricingEngineInterface::class,
      \App\Domain\Pricing\CampaignPricingEngine::class
  );
  ```
* **Reason**: Enables automated dependency resolution for `PricingEngineInterface`.
* **Behavioral Impact**: Resolves `CampaignPricingEngine` whenever `PricingEngineInterface` is requested.
* **Tests**: Full test suite (`php artisan test`).
* **Rollback**: Remove binding line.

---

### 6. `app/Http/Controllers/Api/PricingEngineController.php`
* **Change**: Injected `LegacyPricingEngineAdapter` in place of direct `PricingEngine` dependency.
* **Reason**: Decouples API controller from legacy domain/service layer.
* **Behavioral Impact**: None; endpoint `POST /api/v2/pricing/calculate` behaves identically.
* **Tests**: `tests/Feature/Golden/GoldenApiContractRegressionTest.php::test_v2_pricing_calculate_contract_success_and_validation`.
* **Rollback**: Re-inject `PricingEngine`.

---

### 7. `app/Services/CampaignPricingService.php`
* **Change**: Injected `PricingEngineInterface $pricingEngine` into constructor; delegated `calculateCampaignPreview()`, `validateMinimumDuration()`, and `convertMinDurationToDays()` to `$this->pricingEngine`.
* **Reason**: Maintains `CampaignPricingService` as a safe compatibility facade for existing consumers (`BookingController`, `ManualBookingService`, `BudgetRecommendationService`) while ensuring all pricing calculations flow through the canonical domain engine.
* **Behavioral Impact**: Zero external divergence. Internal calculation routes through canonical domain engine.
* **Tests**: `tests/Feature/Golden/GoldenPricingRegressionTest.php`, `tests/Feature/Golden/GoldenBookingRegressionTest.php`.
* **Rollback**: Restore internal method bodies in `CampaignPricingService`.
