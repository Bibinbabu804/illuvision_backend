# Illuvision Core Backend — Phase 0.3
# Golden vs Desired Behavior

This document records the exact distinctions between **Current Golden Behavior** (what the production system currently does and what our regression suite protects) and **Desired Future Behavior** (what the target re-architecture eventually intends to achieve).

---

## 1. Pricing Engine Divergence

### Current Golden Behavior
- **V1 / Commercial Path:** Handled by `App\Services\CampaignPricingService` via `POST /api/campaigns/calculate`.
  - Dynamically evaluates database-backed `PricingPlan`, `PricingDurationRule`, `PricingScreenRule`, `PricingPeakRule`, and `PricingWeekendRule`.
  - Supports referral code percentage discounts and loyalty reward point deductions.
  - Returns a detailed envelope containing `campaign_summary`, `per_screen`, `aggregated`, and `applied_referral`.
- **V2 / API Path:** Handled by `App\Services\PricingEngine` via `POST /api/v2/pricing/calculate`.
  - Divides base price by 30 (month) or 7 (week) or multiplies by 24 (hour) or 1440 (minute).
  - Uses a hardcoded flat 10% discount for durations `>= 30` days, ignoring all database duration rules.
  - Completely ignores peak hours, weekend surcharges, referral codes, and taxes.
  - Returns `{data: {summary, line_items, pricing}}`.

### Golden Contract
Both endpoints are independently preserved and tested. Neither is refactored or unified in Phase 0.3.

### Desired Future Behavior
A single canonical Domain Pricing Authority (`Domain\Pricing`) should calculate pricing according to server-authoritative database rules, with thin presentation adapters transforming the single domain result into the v1 and v2 API response formats.

### Target Phase & Decision
- **Target Phase:** Phase 1.2 (Domain Pricing Extraction).
- **Decision Required:** Product confirmation on whether v2 pricing should be migrated to adopt canonical database pricing rules or continue honoring the legacy flat 10% rule.

---

## 2. Payment Failure Transaction Rollback

### Current Golden Behavior
- In `App\Services\PaymentService::verifyAndConfirmPayment`:
  ```php
  if (!$verified) {
      $payment->update([
          'payment_status' => 'failed',
          ...
      ]);
      $payment->booking->update(['status' => Booking::STATUS_PAYMENT_FAILED]);
      throw new \Exception('Payment signature verification failed.');
  }
  ```
- Because `throw new \Exception()` is called inside `DB::transaction()`, MySQL rolls back the transaction.
- The update of `payment_status` to `'failed'` is rolled back, leaving the payment and booking in `'pending'` status in the database.

### Golden Contract
The golden test suite (`GoldenPaymentRegressionTest`) asserts that following an invalid signature exception, the transaction rollback leaves the database record in `'pending'` status.

### Desired Future Behavior
The payment verification failure should be committed to the database so that audit logs and status queries accurately reflect `'failed'` without being rolled back, while preventing the booking from transitioning to paid.

### Target Phase & Decision
- **Target Phase:** Phase 1.1 (Payment Gateway Adapter & Transaction Boundary Hardening).
- **Decision Required:** Architecture decision to separate the verification logging transaction from the state transition transaction.

---

## 3. Payment Concurrency & Row Locking

### Current Golden Behavior
- `Payment::where('razorpay_order_id', $razorpayOrderId)->firstOrFail()` executes a standard `SELECT * FROM payments WHERE razorpay_order_id = ? LIMIT 1`.
- It does NOT acquire a row lock (`FOR UPDATE`).
- If two verification requests arrive concurrently for the same order, both can read `payment_status === 'pending'` before either commits.

### Golden Contract
Documented and verified via `GoldenConcurrencyVerificationTest`.

### Desired Future Behavior
Acquire an explicit pessimistic row lock:
```php
$payment = Payment::where('razorpay_order_id', $razorpayOrderId)->lockForUpdate()->firstOrFail();
```
This guarantees strict webhook idempotency at the database engine level.

### Target Phase & Decision
- **Target Phase:** Phase 1.1.

---

## 4. Booking Reference Generation

### Current Golden Behavior
- In `BookingController@storeCampaign` (Advertiser Channel), `booking_reference` is omitted during `Booking::create()`, leaving it as `NULL` in the database.
- In `BookingEngineController@store` (V2 Channel), `booking_reference` is explicitly generated as `'ILL-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 4))`.
- In `ManualBookingService::createCampaignBooking` (Partner & Admin Channel), `booking_reference` is generated as `'BK-' . date('Ymd') . '-' . rand(...)`.

### Golden Contract
Each channel preserves its current creation payload and database representation.

### Desired Future Behavior
A centralized `BookingReferenceGenerator` domain service should automatically assign canonical, formatted reference IDs (`ILL-YYYYMMDD-XXXX`) to every booking across all 4 channels via a unified domain lifecycle event.

### Target Phase & Decision
- **Target Phase:** Phase 1.3 (Booking Domain Service Unification).

---

## 5. Direct External SDK Coupling

### Current Golden Behavior
- `PaymentService` instantiates `new Razorpay\Api\Api($key, $secret)` directly in its constructor.
- `GoogleAuthController` calls `Http::get('https://oauth2.googleapis.com/tokeninfo')` inline.
- `ExchangeRateService` calls `Http::get(...)` directly without fallback circuit breakers.

### Golden Contract
Tests use offline HMAC calculation, Http::fake(), and mocked container instances to preserve test isolation.

### Desired Future Behavior
Introduce clean `Infrastructure\Contracts` interfaces:
- `PaymentGatewayInterface`
- `OAuthVerifierInterface`
- `CurrencyRateProviderInterface`
With concrete adapters (`RazorpayAdapter`, `GoogleOAuthAdapter`, `ExchangeRateApiAdapter`) located exclusively in the `Infrastructure/` layer.

### Target Phase & Decision
- **Target Phase:** Phase 1.1 & Phase 1.4.
