# Phase 0.9 — Payment Architecture Diff

**Generated:** 2026-09-26
**Scope:** Payment boundary extraction, behaviour-preserving.
**Database migrations:** none.
**Public API changes:** none.

---

## 1. What this phase is

Phase 0.9 moves the Payment subsystem behind explicit boundaries without changing a single
externally observable behaviour. No route, request field, response envelope, status string,
monetary value, transaction boundary or event changed.

The only *architectural* defect fixed is the direct Razorpay SDK instantiation in
`RefundService`.

---

## 2. Files created (9)

### Domain

| File | Purpose |
|---|---|
| `app/Domain/Payment/Support/Money.php` | Single audited home for paise/rupee conversion and full-refund determination. Pure static methods, no framework. |
| `app/Domain/Payment/PaymentStatus.php` | Read-only constant aliases over `App\Models\Payment`. No logic, no enum, no transitions. |
| `app/Domain/Payment/Contracts/PaymentRepositoryInterface.php` | Persistence contract for payment reads/writes. |

### Infrastructure

| File | Purpose |
|---|---|
| `app/Infrastructure/Payments/Persistence/EloquentPaymentRepository.php` | Eloquent adapter. Reproduces the legacy query shapes verbatim. |

### Application

| File | Purpose |
|---|---|
| `app/Application/Payment/UseCases/VerifyPaymentUseCase.php` | Thin delegation to `PaymentService::verifyPaymentSignature()`. |
| `app/Application/Payment/UseCases/GetPaymentStatusUseCase.php` | Payment-status read entry point. |
| `app/Application/Payment/UseCases/PaymentStatusProjector.php` | Builds the 8-column status read model, lifted verbatim out of the controller. |
| `app/Application/Payment/UseCases/CreateOfflinePaymentUseCase.php` | Offline payment entry point. |
| `app/Application/Payment/UseCases/RefundPaymentUseCase.php` | Canonical refund entry point covering both the recorded and legacy refund paths. |

---

## 3. Files modified (7)

### `app/Services/RefundService.php` — architecture violation removed

**Before**
```php
use Razorpay\Api\Api;

public function initiateRefund(...)
{
    return DB::transaction(function () use (...) {
        $api = new Api();
        $api->setKey($key);
        $api->setSecret($secret);

        $razorpayRefund = $api->payment->refund($payment->razorpay_payment_id, [...]);
        // ...
    });
}
```

**After**
```php
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Support\Money;

public function __construct(protected PaymentGatewayInterface $gateway) {}

public function initiateRefund(...)
{
    return DB::transaction(function () use (...) {
        $razorpayRefund = $this->gateway->refund(
            $payment->razorpay_payment_id,
            Money::toPaise($amount),
            ['payment_id' => ..., 'booking_id' => ..., 'reason' => ...]
        );
        // ... identical writes
    });
}
```

**Why:** `ARCHITECTURE_RULES.md` requires vendor SDKs to live in infrastructure. `RazorpayPaymentGateway`
already existed as a compliant adapter; `RefundService` was simply not using it.

**Verified unchanged:** the transaction boundary, the write order, the `metadata.razorpay_response`
payload, the full-vs-partial determination, the booking `refunded` coupling, and the
`PaymentLog` write are all byte-identical.

### `app/Services/PaymentService.php`

- Injects `PaymentRepositoryInterface`; the two gateway-order lookups and the payment insert move behind it.
- Replaces three inline `(int) round($x * 100)` conversions with `Money::toPaise()`.
- Replaces the full-refund ternary with `Money::isFullRefund()`.
- **Not changed:** the `DB::transaction` boundaries, the exception-throw-after-write pattern in the
  failure branch, the `PaymentReceived` dispatch, the referral-attribution call.

### `app/Application/Payment/UseCases/CreatePaymentOrderUseCase.php`

- Injects `PaymentRepositoryInterface` for the pending-payment reuse lookup.
- **Not changed:** the response array shape (`is_existing`, `razorpay_order_id`, `amount`, `currency`),
  the reuse condition, or the delegation to `PaymentService::createOrder()`.

### `app/Http/Controllers/PaymentController.php`

- Injects `CreatePaymentOrderUseCase`, `VerifyPaymentUseCase`, `GetPaymentStatusUseCase`, `RefundPaymentUseCase`.
- `verify()` delegates to the use case; `paymentStatus()` delegates to the projector.
- **Not changed:** route paths, validation rules, response envelopes, status codes, or auth.

### `app/Http/Controllers/AdminPaymentController.php`

- `initiateRefund()` delegates to `RefundPaymentUseCase` instead of `RefundService` directly.
- `approvePayment()` replaces `app(ReferralTrackingService::class)` with the injected
  `RecordReferralAttributionUseCase`.
- **Not changed:** the controller's own `DB::transaction` boundaries or the `rejectPayment()`
  service locator (it performs a *cancellation*, which the attribution use case does not model).

### `app/Http/Controllers/AdminManualBookingController.php`

- `payment()` delegates to `CreateOfflinePaymentUseCase`.

### `app/Providers/AppServiceProvider.php`

- Registers `PaymentRepositoryInterface` → `EloquentPaymentRepository`.

---

## 4. Boundary diagram

```
BEFORE
  PaymentController ──────────────► PaymentService ──► Razorpay\\Api\\Api   (via config)
  AdminPaymentController ────────► RefundService  ──► Razorpay\\Api\\Api   ← VIOLATION
  ManualBookingService ──────────► PaymentService
  (services query Payment::* directly)

AFTER
  PaymentController ──► CreatePaymentOrderUseCase ──┐
                     ──► VerifyPaymentUseCase ──────┤
                     ──► GetPaymentStatusUseCase ───┤──► PaymentService ──► PaymentRepositoryInterface
                     ──► RefundPaymentUseCase ──────┤         │                    ▲
  AdminPaymentController ──► RefundPaymentUseCase ───┤         │                    │
  AdminManualBookingController ──► CreateOfflinePaymentUseCase ┘         │            │
                                                                   Money │            │
                                                                        │            │
  AdminPaymentController ──► RecordReferralAttributionUseCase          ▼            │
                                                          PaymentGatewayInterface    │
                                                                        │            │
                                                          ┌─────────────────────────┘
                                                          ▼
                                              RazorpayPaymentGateway (infrastructure)
```

---

## 5. Architectural rules compliance

| Rule | Before | After |
|---|---|---|
| HTTP layer must not reach SDKs / low-level HTTP | Pass | Pass |
| Domain must be framework-free | N/A (no domain layer) | Pass — `Money` and `PaymentStatus` have no framework imports |
| Vendor SDKs confined to infrastructure | **Fail** (`RefundService`) | Pass |
| Financial/network operations outside row locks | Pre-existing violation (recorded) | Unchanged — recorded, not silently altered |
| Stable API contracts | Pass | Pass |

---

## 6. Preserved-on-purpose oddities

These were found during the audit and deliberately **left unchanged**, because changing any of them
would alter observable behaviour:

1. `PaymentController::refund()` is unrouted dead code with a materially different implementation
   (no `refunds` row, no `PaymentLog`) from the live `AdminPaymentController::initiateRefund()`.
2. `BookingController::markManualPayment()` and `ManualBookingService::recordOfflinePayment()` are
   two divergent offline-payment paths.
3. The signature-failure branch updates the payment and booking status and then throws *inside* the
   transaction, so the writes roll back and the state is unchanged.
4. Two overlapping payment status vocabularies exist (`paid` vs `completed`, shared `pending`).
5. `RevenueDistributionService::getProviderRevenueStats()` references an undefined variable; the
   method has no callers.
6. The refund gateway call happens inside a DB transaction.

All six are recorded in the audit artifacts with a rationale. None were "fixed" silently.

---

## 7. Validation

| Check | Result |
|---|---|
| `php -l` on all modified files | Pass |
| `composer validate` | Pass |
| `php artisan migrate:status` | No pending migrations |
| Vendor SDK leak scan outside `app/Infrastructure` | Clean |
| HTTP-layer HTTP-client scan | Clean |
| `Tests\Feature\Golden\GoldenPaymentRegressionTest` | 4/4 pass |
| `Tests\Feature\Golden\GoldenApiContractRegressionTest` | Pass |
| `Tests\Feature\Golden\GoldenConcurrencyVerificationTest` | 3/3 pass |
| `Tests\Feature\Golden\GoldenPaymentBoundaryTest` (new) | 12/12 pass |
| `Tests\Unit\Domain\Payment\MoneyTest` (new) | 15/15 pass |
| `Tests\Unit\Domain\Payment\PaymentStatusTest` (new) | 4/4 pass |
| `Tests\Unit\Application\Payment\CreatePaymentOrderUseCaseTest` | 2/2 pass |
| Full suite | 451 pass, 2 fail (both pre-existing, unrelated) |
