# Phase 0.9 + 0.10 — Final Report

**Generated:** 2026-09-26
**Phases:** 0.9 (Payment boundary) + 0.10 (Financial boundary), executed together
**Repository:** `core-backend` — Laravel 11.55.1, PHP 8.2.26
**Status:** COMPLETE — behaviour-preserving, all validation green except two pre-existing failures

---

## 1. Executive summary

Both boundaries were extracted successfully with **zero externally observable change**.

| Dimension | Result |
|---|---|
| Schema migrations | **0** |
| API contract changes | **0** |
| Status string changes | **0** |
| Monetary value changes | **0** |
| Transaction boundaries added / removed / altered | **0** |
| Pessimistic locks added / removed / weakened | **0** |
| Architecture violations eliminated | **1** (vendor SDK outside infrastructure) |
| Source files created | **17** |
| Source files modified | **15** |
| Source files deleted | **0** |
| Audit artifacts produced | **20** |
| Test cases added | **55** |
| Test suite | **451 passed** (was 387), 2 pre-existing failures unchanged |

The single most important outcome is not a line of code — it is that **every quirk, race and
divergence found during the audit was preserved and documented rather than quietly "fixed"**. In a
subsystem that moves real money and reconciles against historical snapshots, an extraction that
improves the arithmetic is an extraction that changes the books.

---

## 2. The one defect that was actually fixed

`App\Services\RefundService` instantiated `Razorpay\Api\Api` directly, in violation of the rule that
vendor SDKs live in infrastructure. A compliant `RazorpayPaymentGateway` adapter already existed and
was simply not being used.

**Fix:** inject `PaymentGatewayInterface` and route the refund through it, keeping the service body —
transaction boundary, write order, metadata payload, guards, exception propagation — byte-identical.

**Verified:** the only `Razorpay\Api\Api` import in `app/` is now inside
`app/Infrastructure/Payments/Razorpay/`, and a test fails if that ever regresses.

---

## 3. Boundaries created

### Domain — new, framework-free

| Class | Role |
|---|---|
| `Domain\Payment\Support\Money` | Single audited home for paise/rupee conversion and full-refund determination |
| `Domain\Payment\PaymentStatus` | Read-only constant aliases over `App\Models\Payment` |
| `Domain\Payment\Contracts\PaymentRepositoryInterface` | Payment persistence contract |
| `Domain\Financial\Support\RevenueAllocation` | Pure gross/split/Illuvision-owned arithmetic |
| `Domain\Financial\Contracts\SettlementRepositoryInterface` | Settlement eligibility contract |
| `Domain\Financial\Contracts\PartnerCommissionRepositoryInterface` | Commission persistence contract |

Zero framework imports across all six. Verified statically and by test.

### Infrastructure — new adapters

`EloquentPaymentRepository`, `EloquentSettlementRepository`, `EloquentPartnerCommissionRepository`.
Each reproduces its legacy query shape verbatim, including the settlement query's two-branch structure
and the commission `lockForUpdate()`.

### Application — new use cases

`CreatePaymentOrderUseCase` (modified), `VerifyPaymentUseCase`, `GetPaymentStatusUseCase`,
`PaymentStatusProjector`, `CreateOfflinePaymentUseCase`, `RefundPaymentUseCase`,
`DistributeBookingRevenueUseCase`, `GenerateProviderSettlementUseCase`,
`RecordReferralAttributionUseCase`.

---

## 4. Findings preserved rather than fixed

This is the substance of the audit. Each was found, assessed, and deliberately left intact because
changing it would alter behaviour.

### Financial correctness

1. **Sub-paisa rounding drift.** `platform_share + provider_share` can differ from `gross_revenue` by
   up to 0.01, because each share is computed from the already-rounded gross and rounds
   independently. "Fixing" this by computing `provider = gross - platform` would make newly settled
   periods disagree with already-settled ones. → Reconciliation concern, never a calculation change.
   *(ADR-P10-01)*

2. **Settlements must sum snapshots, never live percentages.** A settlement generated today for a
   period that ran last quarter would otherwise change value depending on generation date. Pinned by
   a test that sets live percentages to a value contradicting the snapshot and asserts the snapshot
   wins. *(ADR-P10-03)*

### Concurrency

3. **Concurrent settlement generation can double-settle revenue (HIGH).** The eligibility read is
   outside the transaction and takes no lock, so two admins generating overlapping periods both see
   the same pending screens. → Needs a dedicated concurrency phase; fixing it naively would introduce
   deadlocks against the booking and inventory lock order. *(RISK-01)*

4. **Concurrent order creation can create duplicate gateway orders.** The pending-payment reuse check
   and the insert are not atomic. *(RISK-02)*

5. **Concurrent verification has no serialisation.** The duplicate-paid guard is read-then-write with
   no row lock. *(RISK-03)*

6. **The refund gateway call happens inside a DB transaction**, holding the transaction open for the
   full network round-trip. Moving it out would risk a debited gateway with no local record on a
   subsequent DB failure. *(RISK-04, ADR-P09-01 context)*

### Structural divergence

7. **Two different refund implementations.** The live admin path writes a `refunds` row and a
   `PaymentLog`; the unrouted legacy path writes neither and treats a null amount as a full refund.
   Unifying them requires a product decision on four observable differences. *(ADR-P09-05)*

8. **Two different offline-payment paths.** `BookingController::markManualPayment()` and
   `ManualBookingService::recordOfflinePayment()` diverge in status handling, event dispatch and
   referral attribution. *(ADR-P09-06)*

9. **Two overlapping payment status vocabularies.** `'pending'` is shared; `'paid'`/`'completed'` and
   `'partially_refunded'` are not. An enum would force a choice that breaks either persisted data or a
   live flow. Pinned by a test asserting they stay partially distinct. *(ADR-P09-07)*

10. **`RevenueDistributionService::getProviderRevenueStats()`** references an undefined variable
    `$totalScreensInBookings`. Zero callers. Not fixed — an unrequested behaviour change.

11. **The signature-failure branch writes status changes and then throws inside the same
    transaction**, so the writes roll back and state is unchanged. Preserved.

12. **The settlement `bookingId` branch skips the period filter entirely.** A year-old booking is
    still settleable when explicitly targeted. Pinned by a test so a future refactor cannot silently
    change it.

---

## 5. Validation

### Suite

| | Before | After |
|---|---|---|
| Passed | 387 | **451** (+64) |
| Failed | 2 | 2 (same two) |
| Warning | 1 | 1 (PHPUnit doc-comment metadata) |
| Skipped | 4 | 4 |
| Assertions | 2201 | 4843 |

### The two failures are pre-existing and unrelated

`Tests\Feature\BookingConcurrencyTest` and `Tests\Feature\CampaignBookingPricingIntegrityTest` both
assert the message `"no longer available"` but receive
`"Asset 'X' has only N units available, but M requested."`. The two emitters
(`BookingController:110`, `CampaignPricingService:82`) and the two suppressed emitters
(`BookingCapacityService:223`, `InventoryService:236`) are all untouched by this work. The baseline
captured **before any edit** showed the identical two failures.

### Static checks

| Check | Result |
|---|---|
| `php -l` on all created/modified files | Pass |
| `composer validate` | Pass |
| `php artisan migrate:status` | 0 pending |
| Vendor SDK outside `app/Infrastructure` | 0 |
| HTTP client usage in `app/Http` | 0 |
| All 15 new JSON artifacts parse | Pass |

### Load-bearing regression guards added

1. `test_no_vendor_sdk_import_remains_in_services_or_domain` — the fixed violation cannot return.
2. `test_commission_repository_preserves_pessimistic_locking` — the only serialised financial write
   cannot be weakened.
3. `test_generated_settlement_sums_snapshots_not_live_percentages` — settlements cannot start using
   live percentages.
4. `test_both_status_vocabularies_are_preserved_verbatim` — the two status sets cannot be silently
   merged.
5. `test_rounding_drift_is_preserved_not_corrected` — the sub-paisa drift cannot be "fixed" by
   changing the arithmetic.
6. `test_refund_failure_rolls_back_partial_refund_rows` — the refund rollback contract is explicit.

---

## 6. Honest limitations

- **The application use cases still delegate to the legacy services.** The boundary value that
  matters was delivered — the HTTP layer no longer reaches services, and no vendor SDK lives outside
  infrastructure — but roughly a thousand lines of settlement, commission and referral logic have not
  moved into the use cases. Moving them inside a "change no behaviour" phase would have maximised the
  chance of a silent change. The transitional edge is documented on every use case class so the
  eventual migration is mechanical. *(ADR-P10-02)*

- **One persistence exception.** `PaymentStatusProjector` queries `Booking`/`Payment` directly as a
  read projection. A query-side repository for a single 8-column projection was judged
  over-engineering, and the class is a read-model builder rather than a business-rule owner. Documented
  on the class and in the boundary map.

- **One inverted dependency accepted.** `ReferralTrackingService` calls
  `DistributeBookingRevenueUseCase` for its revenue backfill — a service depending on the application
  layer. Isolated to one call site and documented. *(ADR-P10-04)*

- **Four rules were deliberately not extracted.** The settlement lifecycle, the split resolver, the
  referral cancellation path, and the legacy unrouted refund remain on their services. Each is
  recorded in the migration ledger with a deferral reason.

- **Seven concurrency risks remain open**, catalogued with severity and rationale. None were
  introduced or worsened by this work. The highest (RISK-01, double-settlement) needs a locking
  strategy designed against existing lock order and should not be attempted as a side effect of
  another phase.

---

## 7. Artifact index

### Phase 0.9 — Payment

| Artifact | Contents |
|---|---|
| `phase-0.9-payment-responsibility-map.json` | 12 rules, each with legacy path, canonical owner, golden test, status |
| `phase-0.9-payment-dependency-diff.json` | 11 edges added, 3 removed, 1 service locator eliminated, 1 violation accepted |
| `phase-0.9-payment-transaction-map.json` | 6 transactions with lock and network-call profiles; 6 preserved oddities |
| `phase-0.9-payment-idempotency-map.json` | 4 existing mechanisms, 4 absent, 0 added |
| `phase-0.9-payment-state-transition-map.json` | 9 transitions, both status vocabularies, 4 invariant assertions |
| `phase-0.9-payment-architecture-diff.md` | Before/after per file, boundary diagrams, rule compliance table |
| `phase-0.9-payment-migration-ledger.json` | 9 completed entries, 3 deferred with reasons |

### Phase 0.10 — Financial

| Artifact | Contents |
|---|---|
| `phase-0.10-financial-responsibility-map.json` | 11 rules; revenue, settlement, commission, referral |
| `phase-0.10-revenue-distribution-map.json` | Inputs, split precedence, worked examples, rewired call sites |
| `phase-0.10-settlement-map.json` | Eligibility predicate, computation, transaction, lifecycle |
| `phase-0.10-refund-map.json` | Both refund implementations, 4 behavioural differences, failure semantics |
| `phase-0.10-financial-dependency-diff.json` | 12 edges added, 2 removed, 2 locators eliminated, 3 accepted |
| `phase-0.10-financial-transaction-map.json` | 6 transactions, complete lock inventory |
| `phase-0.10-financial-architecture-diff.md` | Before/after per file, boundary diagrams, 6 preserved oddities |
| `phase-0.10-financial-migration-ledger.json` | 6 completed, 3 deferred with reasons |

### Combined

| Artifact | Contents |
|---|---|
| `phase-0.9-0.10-financial-concurrency-verification.json` | Lock inventory before/after, 10 boundary comparisons, 7 catalogued risks |
| `phase-0.9-0.10-financial-boundary-map.json` | Layer inventory, 7 architecture rules, cross-boundary deps, transitional debt |
| `phase-0.9-0.10-architecture-decisions.md` | 13 ADRs, each with decision, rejected alternative, consequence |
| `phase-0.9-0.10-production-changes.md` | Deploy risk, container bindings, constructor changes, smoke checks, rollback |
| `phase-0.9-0.10-final-report.md` | This document |

---

## 8. Recommended next steps

1. **RISK-01 — settlement double-counting (HIGH).** Design a serialisation strategy against the
   existing booking and inventory lock order, then implement it in a dedicated phase.
2. **RISK-02 / RISK-05 — gateway idempotency.** Add an idempotency key plus a pending-intent record
   written *before* the gateway call. This also resolves RISK-04.
3. **Availability message phase.** Fix the two pre-existing test failures by deciding whether
   `BookingCapacityService`/`InventoryService` messages should surface instead of the generic
   "no longer available" text.
4. **ADR-P10-02 — move service bodies behind use cases.** With the boundaries in place and the golden
   suites green, this is now a mechanical, low-risk refactor.
5. **ADR-P09-05 / P09-06 — product decisions.** Consolidating the duplicate refund and offline-payment
   paths needs business input, not engineering judgement.
