# Phase 0.9 + 0.10 — Production Changes

**Generated:** 2026-09-26
**Audience:** whoever deploys this.

---

## 1. Deployment risk assessment

| Dimension | Assessment |
|---|---|
| Schema migrations | **None.** No migration is added, modified or removed. |
| API contract changes | **None.** No route, request field, response envelope, or status code changes. |
| Stored data changes | **None.** No backfill, no data rewrite, no monetary recomputation. |
| Config / env changes | **None.** |
| New runtime dependencies | **None.** No composer packages added. |
| Feature flags | **None needed.** |
| Rollback strategy | Standard code revert. There is no data to unwind. |

**Overall risk: low.** The change is structural refactoring with an explicit
behaviour-preservation contract, verified by the golden suites.

---

## 2. What actually changes at runtime

Nothing observable. What changes is **which class does the work**:

- `RefundService` now receives its gateway through the container instead of building it.
- Controllers now receive use cases through the container instead of services.
- Persistence and arithmetic moved behind interfaces.

The Laravel container resolves all of these automatically via the bindings added to
`AppServiceProvider`. **No bootstrap, no config publish, no cache clear is required** beyond the
normal deploy step.

---

## 3. New container bindings

Added to `app/Providers/AppServiceProvider::register()`:

| Binding | Implementation |
|---|---|
| `App\Domain\Payment\Contracts\PaymentRepositoryInterface` | `App\Infrastructure\Payments\Persistence\EloquentPaymentRepository` |
| `App\Domain\Financial\Contracts\SettlementRepositoryInterface` | `App\Infrastructure\Financial\Persistence\EloquentSettlementRepository` |
| `App\Domain\Financial\Contracts\PartnerCommissionRepositoryInterface` | `App\Infrastructure\Financial\Persistence\EloquentPartnerCommissionRepository` |

If config or route caching is enabled, the standard cache rebuild applies:

```bash
php artisan optimize:clear
```

---

## 4. Constructor signature changes

These are the only signatures that changed. Any code constructing these classes **manually** (rather
than through the container) must be updated.

| Class | Change |
|---|---|
| `App\Services\PaymentService` | + `PaymentRepositoryInterface` (4th parameter) |
| `App\Services\RefundService` | was implicitly constructible; now requires `PaymentGatewayInterface` |
| `App\Services\SettlementService` | + `SettlementRepositoryInterface` (1st parameter) |
| `App\Services\CommissionService` | + `PartnerCommissionRepositoryInterface` |
| `App\Services\ReferralTrackingService` | + `PartnerCommissionRepositoryInterface`, + `DistributeBookingRevenueUseCase` |
| `App\Services\ManualBookingService` | `RevenueDistributionService` replaced by `DistributeBookingRevenueUseCase`; + `RecordReferralAttributionUseCase` |
| `App\Http\Controllers\PaymentController` | `PaymentService` replaced by four use cases |
| `App\Http\Controllers\AdminPaymentController` | + `RefundPaymentUseCase`, + `RecordReferralAttributionUseCase` |
| `App\Http\Controllers\AdminSettlementController` | + `GenerateProviderSettlementUseCase` |
| `App\Http\Controllers\AdminManualBookingController` | + `CreateOfflinePaymentUseCase` |
| `App\Http\Controllers\BookingController` | `RevenueDistributionService` replaced by `DistributeBookingRevenueUseCase` |
| `App\Application\Payment\UseCases\CreatePaymentOrderUseCase` | + `PaymentRepositoryInterface` (2nd parameter) |

**Verified:** no production code constructs any of these with `new`. The only manual construction in
the repository is in `CreatePaymentOrderUseCaseTest`, which was updated. Laravel's router resolves
controllers through the container, so all HTTP entry points resolve automatically.

---

## 5. Verification performed before merge

| Check | Result |
|---|---|
| `php -l` on all 20 created/modified source files | Pass |
| `composer validate` | Pass — `./composer.json is valid` |
| `php artisan migrate:status` | 0 pending migrations |
| Vendor SDK leak scan outside `app/Infrastructure` | Clean (1 import, correctly located) |
| HTTP-layer HTTP-client scan | Clean |
| **Full test suite** | **451 passed, 2 failed, 1 warning, 4 skipped (4843 assertions)** |

### The 2 failures are pre-existing and unrelated

Both are availability-message wording assertions, and both fail identically on the unmodified
baseline:

- `Tests\Feature\BookingConcurrencyTest > second booking rejected af…`
- `Tests\Feature\CampaignBookingPricingIntegrityTest > booking rejec…`

Both expect the message `"no longer available"` (emitted by
`BookingController:110` / `CampaignPricingService:82`) but receive
`"Asset 'X' has only N units available, but M requested."` (emitted by
`BookingCapacityService:223` / `InventoryService:236`). Neither file was touched by this work. The
baseline before any edits was `387 passed, 2 failed, 1 warning, 4 skipped` — the same two failures.

**Do not attribute these to this change.** They belong to a separate availability-message phase.

---

## 6. New test coverage added

| File | Cases | Purpose |
|---|---|---|
| `tests/Unit/Domain/Payment/MoneyTest.php` | 15 | Pins paise/rupee arithmetic and refund full/partial semantics to the legacy expressions |
| `tests/Unit/Domain/Payment/PaymentStatusTest.php` | 4 | Pins every status alias to its model constant; asserts the two vocabularies stay partially distinct |
| `tests/Unit/Domain/Financial/RevenueAllocationTest.php` | 9 | Pins the gross/split/Illuvision arithmetic and the preserved rounding drift |
| `tests/Feature/Golden/GoldenPaymentBoundaryTest.php` | 12 | Container resolution, SDK-leak guard, refund rollback, status projection, transaction rollback |
| `tests/Feature/Golden/GoldenFinancialBoundaryTest.php` | 15 | Settlement eligibility filters, snapshot-source invariant, booking-id branch, commission lock preservation |
| `tests/Unit/Application/Payment/CreatePaymentOrderUseCaseTest.php` | 2 (updated) | Constructor signature updated for the new repository dependency |

**Net: 55 new test cases, 2567 additional assertions in the unit suites alone.**

Four of these are load-bearing guards against regression of the constraints this work established:

1. `test_no_vendor_sdk_import_remains_in_services_or_domain` — fails if a vendor SDK is reintroduced
   outside infrastructure.
2. `test_commission_repository_preserves_pessimistic_locking` — fails if the `lockForUpdate()` is
   weakened during a future refactor.
3. `test_generated_settlement_sums_snapshots_not_live_percentages` — fails if anyone starts computing
   settlements from live percentages.
4. `test_both_status_vocabularies_are_preserved_verbatim` — fails if the two status vocabularies are
   silently merged.

---

## 7. Post-deploy smoke checks

Nothing new is exposed, so the smoke checks are the existing payment and financial paths. These are
the flows whose delegation changed:

```bash
# 1. Payment order creation (now via CreatePaymentOrderUseCase)
curl -X POST /api/payments/razorpay/create-order \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"booking_id": <ID>}'
# Expect: razorpay_order_id, amount in paise, currency INR, is_existing flag

# 2. Payment status (now via GetPaymentStatusUseCase + PaymentStatusProjector)
curl -H "Authorization: Bearer $TOKEN" /api/bookings/<ID>/payment-status
# Expect: booking_id, booking_status, payment{8 fields} or null

# 3. Partial refund (now via RefundPaymentUseCase -> RefundService -> gateway interface)
curl -X POST /api/admin/payments/<ID>/refund \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"amount": 100, "reason": "smoke test"}'
# Expect: refunds row created, payment_status = partially_refunded, refund_status = processed,
#          booking status UNCHANGED

# 4. Full refund (amount >= payment amount)
# Expect: payment_status = refunded, booking status = refunded

# 5. Settlement generation (now via GenerateProviderSettlementUseCase)
curl -X POST /api/admin/screen-providers/settlements \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"screen_provider_id": <ID>, "period_start": "2026-08-01", "period_end": "2026-08-31"}'
# Expect: settlement created, booking_screens marked settled, activity logged
```

**Failure-mode checks worth running once:**

- Issue a refund against a fake payment id and confirm the `RuntimeException` / 404 behaviour is
  unchanged (the gateway must not be called).
- Trigger a gateway failure on a refund and confirm **no** `refunds` row and **no** `PaymentLog` row
  survive — this is the rollback contract.
- Generate a settlement for a period with no eligible screens and confirm the message is exactly
  `"No eligible booking screens found for the given period."`.

---

## 8. Rollback

```bash
git revert <merge-commit>
php artisan optimize:clear
```

No data migration to reverse. No configuration to restore. **Rollback is a plain revert.**

---

## 9. Deferred work — do not assume these are fixed

This change deliberately left seven concurrency risks and six structural oddities in place. They are
catalogued in `phase-0.9-0.10-financial-concurrency-verification.json`,
`phase-0.9-payment-idempotency-map.json` and `phase-0.9-0.10-architecture-decisions.md`.

The highest-value follow-ups, in order:

1. **Concurrent settlement generation can double-settle revenue** (RISK-01, HIGH). Needs a locking or
   uniqueness strategy designed against the booking and inventory lock order.
2. **Concurrent order creation can create duplicate gateway orders** (RISK-02, MEDIUM). Needs a
   gateway idempotency key and a schema migration.
3. **Refund gateway call inside a DB transaction** (RISK-04, MEDIUM). Needs a pending-intent record
   written before the gateway call plus a gateway idempotency key.
4. **Rounding drift between gross and the sum of shares** (ADR-P10-01). A reconciliation concern; it
   must never be "fixed" by changing the calculation.
5. **The unrouted legacy refund path** (ADR-P09-05) and **the second offline payment path**
   (ADR-P09-06) — both need a product decision before they can be consolidated.
