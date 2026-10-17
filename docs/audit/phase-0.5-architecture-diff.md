# Phase 0.5 — Architecture Diff Report

## 1. Overview
Phase 0.5 introduces the first real, production-safe architectural seams into the Illuvision backend. This document captures the exact architectural transformation (Before vs After) for every component migrated during this phase.

---

## 2. Component 1: Payment Gateway Adapter Seam

### Before
```text
PaymentController
       │
       ▼
PaymentService
       │
       ▼ (Direct tight coupling)
Razorpay\Api\Api SDK
```
* **Issues**: `PaymentService` directly instantiated `new Api($key, $secret)` inside its constructor, coupled business transaction logic with vendor SDK calls, made unit testing impossible without live network mocks, and leaked vendor SDK response objects into payment persistence methods.

### After
```text
PaymentController
       │
       ▼
CreatePaymentOrderUseCase (Application Layer)
       │
       ▼
PaymentService (Domain / Business Orchestration)
       │
       ▼
PaymentGatewayInterface (Domain Contract)
       │
       ▼
RazorpayPaymentGateway (Infrastructure Adapter)
       │
       ▼
Razorpay\Api\Api SDK (Vendor Library)
```
* **Improvements**:
  * Dependency inversion: `PaymentService` depends only on `PaymentGatewayInterface`.
  * Vendor isolation: `Razorpay\Api\Api` is encapsulated entirely within `RazorpayPaymentGateway`.
  * Database transaction safety: Database transactions remain strictly within `PaymentService` / Application layer, while gateway network calls occur outside or via controlled interfaces.
  * 100% offline unit-testable via test doubles.

---

## 3. Component 2: Google Identity Provider Seam

### Before
```text
GoogleAuthController
       │
       ▼ (Direct inline HTTP call)
Http::get('https://oauth2.googleapis.com/tokeninfo')
```
* **Issues**: HTTP controller directly handled third-party HTTP network requests, URL configuration, and remote JSON response extraction.

### After
```text
GoogleAuthController (HTTP Presentation)
       │
       ▼
GoogleIdentityProviderInterface (Infrastructure Contract)
       │
       ▼
GoogleOAuthAdapter (Infrastructure Adapter)
       │
       ▼
Http::get('https://oauth2.googleapis.com/tokeninfo')
```
* **Improvements**:
  * Controller responsibility reduced strictly to request validation, adapter invocation, and user token issuance.
  * Vendor URL, timeout, and response error logging encapsulated inside `GoogleOAuthAdapter`.

---

## 4. Component 3: Exchange Rate API Seam

### Before
```text
ExchangeRateService
       │
       ▼ (Direct inline HTTP call)
Http::timeout(15)->get('https://api.exchangerate-api.com/v4/latest/INR')
```
* **Issues**: `ExchangeRateService` mixed domain currency calculation, database rate persistence, and aggressive cache management with low-level external HTTP network transport.

### After
```text
ExchangeRateService (Domain Currency Calculation & Cache)
       │
       ▼
ExchangeRateApiClientInterface (Infrastructure Contract)
       │
       ▼
ExchangeRateApiClient (Infrastructure Adapter)
       │
       ▼
External ExchangeRate-API HTTP Endpoint
```
* **Improvements**:
  * Decoupled external HTTP client from internal database rate persistence and mathematical conversion routines.
  * Allows plugging in alternate rate providers (e.g. OpenExchangeRates, Fixer) without changing currency logic.

---

## 5. Component 4: First Application Use Cases

### Before
```text
BookingController::calculateCampaignPricing
       │
       ▼
CampaignPricingService::calculateCampaignPreview

PaymentController::createOrder
       │
       ▼
Inline booking ownership checks + PaymentService::createOrder
```

### After
```text
BookingController::calculateCampaignPricing
       │
       ▼
CalculateCampaignPriceUseCase (Application Layer)
       │
       ▼
CampaignPricingService

PaymentController::createOrder
       │
       ▼
CreatePaymentOrderUseCase (Application Layer)
       │
       ▼
PaymentService ──► PaymentGatewayInterface
```
* **Improvements**:
  * Established the standard `HTTP Controller -> Application Use Case -> Domain Service` architectural pipeline.
  * Existing response structure, validation rules, status codes (201 vs 200), and exception contracts preserved with zero drift.
