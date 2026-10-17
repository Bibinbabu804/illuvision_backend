# Illuvision Core Backend Re-Architecture
# Phase 0.3 — Golden Regression Suite & Contract Hardening Report

---

## 1. Executive Summary

- **Phase Status:** `COMPLETE`
- **Tests Before Phase 0.3:** 344 tests (337 passed, 2 failed, 1 warning, 4 skipped)
- **Tests Added in Phase 0.3:** 34 tests across 7 regression suites (100% passing, 230 assertions)
- **Total Tests After Phase 0.3:** 378 tests (371 passed, 2 existing baseline failures, 1 warning, 4 skipped)
- **API Contracts Protected:** 8 critical commercial endpoints
- **Key Risks Verified & Captured:**
  - Dual pricing divergence between v1 (`CampaignPricingService`) and v2 (`PricingEngine`)
  - Payment failure transaction rollback reverting `payment_status` to `'pending'`
  - Non-locking `firstOrFail()` retrieval in payment verification
  - Screen-level serialization mutex in `InventoryService::lockScreensForBooking`

---

## 2. Phase 0.2 Discrepancy & Verification Results

### 2.1 Booking Lock Verification
- **Execution Path:**
  `BookingController@storeCampaign` → `DB::transaction()` → line 101: `InventoryService::lockScreensForBooking($validated['screen_ids'])`.
- **SQL Generated:**
  `SELECT * FROM screens WHERE id IN (?) ORDER BY id ASC FOR UPDATE`
- **Lock Scope:**
  Locks the exact rows in the `screens` table corresponding to the requested screens, ordered deterministically by `id ASC`.
- **Timing:**
  Acquired immediately at the start of the transaction before any availability, capacity, duration, or pricing checks.
- **Concurrency Protection:**
  Any concurrent booking for the same screen(s) blocks on `lockScreensForBooking` at the MySQL engine level until the first transaction commits. When the second transaction proceeds, it observes the committed `booking_screens` rows in `BookingCapacityService::checkEligibility` and rejects the overbooking if capacity is exhausted.
- **Row Lock Limitation:**
  Does NOT take `FOR UPDATE` on `booking_screens` or `bookings` rows directly. The `screens` row acts as the serialization mutex.

### 2.2 Payment Locking Verification
- **Execution Path:**
  `PaymentService::verifyAndConfirmPayment` → line 95:
  `$payment = Payment::where('razorpay_order_id', $razorpayOrderId)->firstOrFail();`
- **SQL Generated:**
  `SELECT * FROM payments WHERE razorpay_order_id = ? LIMIT 1`
- **Finding:**
  There is NO `lockForUpdate()`. The operation is a non-locking `SELECT`. If concurrent webhook delivery occurs, both requests can observe `payment_status === 'pending'` before either commits.

### 2.3 Booking Ownership Architecture
The 4 distinct booking entry points were verified and characterized:
1. **Advertiser Channel:** [`BookingController@storeCampaign`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/app/Http/Controllers/BookingController.php#L58) (`POST /api/bookings/campaign`)
2. **Partner Channel:** [`PartnerBookingController@store`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/app/Http/Controllers/PartnerBookingController.php#L147) (`POST /api/partner/bookings`) → orchestrates via `ManualBookingService::createCampaignBooking`
3. **Admin Channel:** [`AdminManualBookingController@store`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/app/Http/Controllers/AdminManualBookingController.php#L70) (`POST /api/admin/manual-bookings`) → orchestrates via `ManualBookingService::createCampaignBooking`
4. **V2 API Channel:** [`Api\BookingEngineController@store`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/app/Http/Controllers/Api/BookingEngineController.php#L39) (`POST /api/v2/bookings`) → orchestrates inline transaction

### 2.4 Pricing Divergence Verification
- **Canonical Engine:** [`CampaignPricingService`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/app/Services/CampaignPricingService.php#L44) evaluates database-backed `PricingPlan`, `PricingDurationRule`, `PricingPeakRule`, `PricingWeekendRule`, referral discounts, and taxes.
- **Divergent Engine:** [`PricingEngine`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/app/Services/PricingEngine.php#L24) applies a hardcoded unit division and a flat 10% discount for durations `>= 30` days, ignoring database duration rules, peak hours, weekends, referral codes, and taxes.

---

## 3. Current Test Baseline

```text
Before Phase 0.3:
Total: 344 | Passed: 337 | Failed: 2 | Skipped: 4 | Warning: 1 (Duration: 53.74s)

After Phase 0.3:
Total: 378 | Passed: 371 | Failed: 2 | Skipped: 4 | Warning: 1 (Duration: 58.12s)

Added in Phase 0.3:
Total: 34 new golden regression tests across 7 suites | Passed: 34 (100%) | Assertions: 230
```

---

## 4. API Contracts Protected

1. `POST /api/campaigns/calculate` ([`GoldenApiContractRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenApiContractRegressionTest.php))
2. `POST /api/v2/pricing/calculate` ([`GoldenApiContractRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenApiContractRegressionTest.php))
3. `POST /api/v2/availability/check` ([`GoldenApiContractRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenApiContractRegressionTest.php))
4. `POST /api/bookings/campaign` ([`GoldenApiContractRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenApiContractRegressionTest.php))
5. `POST /api/partner/bookings/calculate` ([`GoldenApiContractRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenApiContractRegressionTest.php))
6. `POST /api/payments/razorpay/create-order` ([`GoldenApiContractRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenApiContractRegressionTest.php))
7. `GET /api/bookings/{id}/payment-status` ([`GoldenApiContractRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenApiContractRegressionTest.php))
8. `POST /api/auth/google` ([`GoldenApiContractRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenApiContractRegressionTest.php))

---

## 5. Pricing Coverage

Tested and documented in [`GoldenPricingRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenPricingRegressionTest.php) and [`golden-pricing-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-pricing-cases.json):
- `CASE-PRICING-001`: Single screen / 1 day (1000 INR base, 0 discount, 1000 final)
- `CASE-PRICING-002`: Single screen / 7 days (7000 base, 5% duration rule = 350 discount, 6650 final)
- `CASE-PRICING-003`: Single screen / 30 days (30000 base, 10% duration rule = 3000 discount, 27000 final)
- `CASE-PRICING-004`: Multiple screens (2 screens * 3 days * 1000 = 6000 base, 6000 final)
- `CASE-PRICING-007`: Referral discount (10% on 1000 = 100 discount, 900 final)
- `CASE-PRICING-010`: V2 automated calculation (2 qty * 5 days * 1000 = 10000 gross)
- `CASE-PRICING-011`: V1 vs V2 mathematical and structural divergence verification

---

## 6. Availability Coverage

Tested in [`GoldenAvailabilityRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenAvailabilityRegressionTest.php) and [`golden-availability-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-availability-cases.json):
- Available screen evaluation (`checkEligibility` returning eligible=true)
- Overlapping intraday interval collision detection on fixed screen (10:00-14:00 vs 12:00-16:00 rejected)
- Cancelled booking capacity release (cancelled bookings do not consume daily or interval capacity)
- Mobile fleet line sweep and threshold limits (10 requested succeeds, 11 requested rejected)
- `POST /api/v2/availability/check` endpoint contract

---

## 7. Booking Coverage

Tested in [`GoldenBookingRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenBookingRegressionTest.php) and [`golden-booking-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-booking-cases.json):
- Channel 1 (Advertiser): `POST /api/bookings/campaign` creates booking with `status: payment_pending`, snapshots `booking_screens` split percentages (80% provider, 20% illuvision)
- Channel 2 (Partner): `POST /api/partner/bookings` injects `created_by_partner_id` and auto-resolves active referral code
- Channel 3 (Admin): `POST /api/admin/manual-bookings` creates client on the fly and sets `booking_source: admin_manual`
- Channel 4 (V2 API): `POST /api/v2/bookings` generates `ILL-YYYYMMDD-XXXX` reference code and `status: pending_approval`

---

## 8. Payment Coverage

Tested in [`GoldenPaymentRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenPaymentRegressionTest.php) and [`golden-payment-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-payment-cases.json):
- Order creation: Paise conversion (`round(final_amount * 100)`), INR currency, `order_` prefix
- Idempotency on existing pending order: Returns 200 with existing order rather than creating duplicate
- HMAC-SHA256 signature verification: Offline cryptographic validation using configured secret
- Successful state transition: `payment_status: paid`, `paid_at: timestamp`, `booking.status: pending_approval`
- Duplicate verification: Rejects second verification with exception `'Payment already verified for this order.'`

---

## 9. Financial Coverage

Tested in [`GoldenFinancialRegressionTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenFinancialRegressionTest.php) and [`golden-financial-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-financial-cases.json):
- Provider revenue distribution: 80% provider share (₹8,000) and 20% platform share (₹2,000) snapshotted on `booking_screens`
- Decimal precision boundary invariance: Paise round-trip and conservation of total across `[0.01, 1.99, 10.01, 99.99, 999.99, 10000.01]`
- Partner commission: 15% on Illuvision platform share (`₹2,000 * 15% = ₹300.00`)

---

## 10. Transaction & Concurrency Coverage

Tested in [`GoldenConcurrencyVerificationTest`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/tests/Feature/Golden/GoldenConcurrencyVerificationTest.php) and [`transaction-concurrency-verification.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/transaction-concurrency-verification.json):
- Proves `InventoryService::lockScreensForBooking` executes `lockForUpdate()` on `screens` table
- Proves `Payment::where(...)->firstOrFail()` executes non-locking `SELECT`
- Proves `DB::transaction()` automatically reverts partial database writes on unhandled exceptions

---

## 11. Authorization Coverage

- Sanctum token authentication enforced on `/api/bookings/campaign` (401 when missing)
- Partner role authorization enforced on `/api/partner/bookings/calculate` (403 for non-partner users)
- Booking ownership authorization enforced on `/api/payments/razorpay/create-order` (403 when user does not own booking)

---

## 12. External Integration Coverage

- **Razorpay:** Signature verification tested via local HMAC-SHA256 without network calls
- **Google OAuth:** External token verification mocked via `Http::fake()` protecting [`GoogleAuthController`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/app/Http/Controllers/Auth/GoogleAuthController.php)
- **ExchangeRate-API:** Covered by deterministic baseline rates and cache

---

## 13. Existing Failures Classification

Two existing test failures were present in the Phase 0.1 baseline and remain intentionally unmodified in Phase 0.3:

1. `Tests\Feature\BookingConcurrencyTest > second booking rejected after first takes single unit`
   - **Classification:** `A — Existing known failure (Assertion string mismatch)`
   - **Reason:** The test asserted `'no longer available'`, whereas the unified capacity engine now returns the accurate message: `"Asset 'Concurrency Fleet' has only 0 units available, but 1 requested."`
   - **Status:** Left untouched per Rule 33.
2. `Tests\Feature\CampaignBookingPricingIntegrityTest > booking rejected when screen units unavailable`
   - **Classification:** `A — Existing known failure (Assertion string mismatch)`
   - **Reason:** The test asserted `'no longer available'`, whereas the engine returns `"Asset 'Kerala Mobile Fleet' has only 10 units available, but 20 requested."`
   - **Status:** Left untouched per Rule 33.

---

## 14. Newly Discovered Risks

1. **Payment Verification Rollback on Invalid Signature (`CRITICAL`):**
   In `PaymentService::verifyAndConfirmPayment`, updating `payment_status = 'failed'` occurs inside `DB::transaction()`. Throwing an exception causes MySQL to roll back the status update, leaving the payment and booking records in `'pending'` status in the database.
2. **Missing Row Lock on Payment Verification (`HIGH`):**
   `Payment::where('razorpay_order_id', ...)->firstOrFail()` lacks `lockForUpdate()`, leaving a window for race conditions if duplicate verification webhooks are delivered concurrently.
3. **Inconsistent Booking Reference Format (`MEDIUM`):**
   Advertiser bookings store `NULL` for `booking_reference`, while Partner bookings use `BK-...` and V2 bookings use `ILL-...`.

---

## 15. Golden vs Desired Behavior

Fully documented in [`docs/audit/golden-vs-desired-behavior.md`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-vs-desired-behavior.md).

---

## 16. Production Changes

- **Production behavior changed:** `NO`
- **Production code changed:** `0 files`
- **Routes changed:** `0`
- **Database/schema changed:** `0`
- **Existing tests modified:** `0`

---

## 17. Refactoring Readiness

With the completion of Phase 0.3, the following areas are now protected by regression contracts and are safe to refactor in future phases:
- **Phase 1.1 (Payment Gateway Infrastructure Adapter):** Full signature verification, order creation, and state transition contracts are now established.
- **Phase 1.2 (Domain Pricing Authority Extraction):** Dual-engine divergence is fully characterized and pinned by tests.
- **Phase 1.3 (Booking Domain Service Unification):** All 4 booking creation channels, locking paths, and snapshot persistence are covered.
- **Phase 1.4 (External Adapters):** Google OAuth and Exchange Rate HTTP integrations are decoupled from live network requirements.

---

## 18. Phase 0.4 Inputs

Phase 0.3 delivers the following complete, verified artifacts for Phase 0.4:
1. [`docs/audit/golden-api-contracts.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-api-contracts.json)
2. [`docs/audit/golden-pricing-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-pricing-cases.json)
3. [`docs/audit/golden-availability-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-availability-cases.json)
4. [`docs/audit/golden-booking-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-booking-cases.json)
5. [`docs/audit/golden-payment-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-payment-cases.json)
6. [`docs/audit/golden-financial-cases.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-financial-cases.json)
7. [`docs/audit/transaction-concurrency-verification.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/transaction-concurrency-verification.json)
8. [`docs/audit/contract-drift-matrix.json`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/contract-drift-matrix.json)
9. [`docs/audit/golden-vs-desired-behavior.md`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/golden-vs-desired-behavior.md)
10. [`docs/audit/phase-0.3-golden-regression-report.md`](file:///Users/apple/my-works/illuvision-ecosystem/core-backend/docs/audit/phase-0.3-golden-regression-report.md)
11. 34 automated regression tests under `tests/Feature/Golden/`
