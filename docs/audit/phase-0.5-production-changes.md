# Phase 0.5 — Production Changes Log

This document records every existing production file modified during Phase 0.5, detailing the reason, architectural purpose, behavioral impact, protecting tests, and rollback strategy.

---

### 1. `app/Providers/AppServiceProvider.php`
* **Reason**: Register Laravel service container bindings for the new Phase 0.5 infrastructure interfaces and adapters.
* **Architectural Purpose**: Dependency inversion (IoC). Enables application and domain layers to depend on interfaces rather than vendor implementations.
* **Behavioral Impact**: Zero. When interfaces are requested, the container resolves the verified adapters.
* **Tests Protecting It**:
  * `Tests\Unit\Infrastructure\Payments\RazorpayPaymentGatewayTest`
  * `Tests\Unit\Infrastructure\Auth\GoogleOAuthAdapterTest`
  * `Tests\Unit\Infrastructure\ExchangeRates\ExchangeRateApiClientTest`
  * `Tests\Feature\Golden\GoldenApiContractRegressionTest`
* **Rollback Approach**: Remove the 3 binding statements from `register()`.

---

### 2. `app/Services/PaymentService.php`
* **Reason**: Decouple `PaymentService` from direct vendor SDK `Razorpay\Api\Api`.
* **Architectural Purpose**: Move external gateway operations behind `PaymentGatewayInterface` while keeping database transactions, booking state transitions, and referral tracking intact.
* **Behavioral Impact**: Zero. Order parameters, paise conversion, signature verification, payment fetching, and refunds execute through the adapter with identical inputs and outputs.
* **Tests Protecting It**:
  * `Tests\Feature\Golden\GoldenPaymentRegressionTest`
  * `Tests\Feature\Golden\GoldenApiContractRegressionTest::test_payment_order_create_contract_and_auth`
  * `Tests\Unit\Application\Payment\CreatePaymentOrderUseCaseTest`
* **Rollback Approach**: Restore `protected Api $razorpay;` in constructor and reinstate direct `$this->razorpay` calls.

---

### 3. `app/Http/Controllers/PaymentController.php`
* **Reason**: Extract order creation business orchestration into `CreatePaymentOrderUseCase`.
* **Architectural Purpose**: Thin HTTP controller to handle strictly validation, authorization, and response formatting, delegating application workflow to the use case.
* **Behavioral Impact**: Zero. Status code (201 vs 200), JSON envelope, and error handling remain 100.00% identical.
* **Tests Protecting It**:
  * `Tests\Feature\Golden\GoldenApiContractRegressionTest::test_payment_order_create_contract_and_auth`
  * `Tests\Unit\Application\Payment\CreatePaymentOrderUseCaseTest`
* **Rollback Approach**: Inline the `existingPending` check and `$this->paymentService->createOrder($booking)` directly in `createOrder()`.

---

### 4. `app/Http/Controllers/Auth/GoogleAuthController.php`
* **Reason**: Remove low-level `Http::get()` call to Google's tokeninfo endpoint from the presentation controller.
* **Architectural Purpose**: Move external authentication verification behind `GoogleIdentityProviderInterface`.
* **Behavioral Impact**: Zero. Controller still validates `credential`, verifies token, and handles user login/registration and Sanctum token issuance.
* **Tests Protecting It**:
  * `Tests\Unit\Infrastructure\Auth\GoogleOAuthAdapterTest`
  * `Tests\Feature\Golden\GoldenApiContractRegressionTest::test_google_auth_contract_with_mocked_tokeninfo`
* **Rollback Approach**: Revert `__invoke` signature to `(Request $request)` and reinstate inline `Http::get('https://oauth2.googleapis.com/tokeninfo', ...)`.

---

### 5. `app/Services/ExchangeRateService.php`
* **Reason**: Decouple external HTTP exchange-rate fetching from internal currency mathematical calculations and caching.
* **Architectural Purpose**: Move external API transport behind `ExchangeRateApiClientInterface`.
* **Behavioral Impact**: Zero. Rate synchronization, caching TTL, fallback rates, and conversion formulas remain unchanged.
* **Tests Protecting It**:
  * `Tests\Unit\Infrastructure\ExchangeRates\ExchangeRateApiClientTest`
  * `Tests\Feature\CurrencyConversionTest`
* **Rollback Approach**: Revert `syncRates()` to use inline `Http::timeout(15)->get($this->apiUrl)`.

---

### 6. `app/Http/Controllers/BookingController.php`
* **Reason**: Route `calculateCampaignPricing` through `CalculateCampaignPriceUseCase`.
* **Architectural Purpose**: Establish the first application use-case boundary in the booking/pricing flow.
* **Behavioral Impact**: Zero. Request validation, authorization, pricing calculations, and response payloads remain identical.
* **Tests Protecting It**:
  * `Tests\Unit\Application\Pricing\CalculateCampaignPriceUseCaseTest`
  * `Tests\Feature\Golden\GoldenPricingRegressionTest`
  * `Tests\Feature\Golden\GoldenApiContractRegressionTest::test_campaign_pricing_calculate_contract_success_and_validation`
* **Rollback Approach**: Revert method signature to `(Request $request)` and call `$this->campaignPricingService->calculateCampaignPreview($validated)`.
