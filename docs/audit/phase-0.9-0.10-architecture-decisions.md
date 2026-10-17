# Phase 0.9 + 0.10 — Architecture Decisions

**Generated:** 2026-09-26
**Scope:** Payment and Financial boundary extraction.
**Decision style:** every entry states the decision, the rejected alternative, and the consequence —
including the consequences that were deliberately accepted as cost.

---

## ADR-P09-01 — Extract the gateway contract usage; do not rewrite the refund service

**Context.** `RefundService` instantiated `Razorpay\Api\Api` directly, violating the rule that
vendor SDKs live in infrastructure. A compliant `RazorpayPaymentGateway` already existed.

**Decision.** Inject `PaymentGatewayInterface` into `RefundService` and route the call through it.
Keep the service body — transaction, write order, metadata payload, guards — byte-identical.

**Rejected.** Rewrite `RefundService` as a clean refund domain service.

**Why not.** A rewrite would have forced decisions on the transaction boundary, the full-refund
semantics, and the `metadata.razorpay_response` shape. Every one of those is externally observable.
The violation was in the *dependency*, not the logic, so only the dependency was changed.

**Consequence.** One architecture violation eliminated at near-zero behavioural risk.

---

## ADR-P09-02 — `Money` reproduces legacy arithmetic, including its quirks

**Context.** Paise conversion was inline in three places. Centralising it is obviously right — but
centralising it *correctly* would change monetary values.

**Decision.** `Money::toPaise()` is literally `(int) round($rupees * 100)`, with no BCMath, no
alternative rounding mode, and no currency abstraction. `toRupees()` is literally
`round($paise / 100, 2)`.

**Rejected.** Convert to BCMath, or introduce a rounding-mode policy, or add multi-currency support.

**Why not.** The stored amounts in `payments`, `refunds` and `booking_screens` were all produced by
this exact expression. Any "improvement" would silently shift historical financial records. The
correct place to change money handling is a dedicated money-migration phase with a reconciliation
plan — not an extraction.

**Consequence.** A single audited home for money conversion, with zero change to any stored value.
`MoneyTest` pins the exact behaviour so a future change must be deliberate.

---

## ADR-P09-03 — Accept the Payment → Financial dependency

**Context.** `PaymentService` calls `ReferralTrackingService` inside the verification transaction to
create referral usage and partner commission. This crosses the boundary the phase is drawing.

**Decision.** Accept it. Do not restructure.

**Rejected.** (a) Remove referral attribution from the payment transition. (b) Introduce a
Payment-owned event that Financial subscribes to. (c) Move commission creation to a separate job.

**Why not.** (a) breaks partner commission tracking for every paid booking. (b) changes transaction
semantics — an out-of-transaction listener would no longer roll back with the payment, and
`RecordReferralAttributionUseCase` explicitly requires a caller-owned transaction. (c) moves a
financial write outside the payment commit, changing failure behaviour.

**Consequence.** The Payment layer depends on the Financial layer. This is a *domain* dependency, not
a layering violation — referral attribution genuinely is part of the payment-success transition. It is
documented on the use case and in the dependency diff.

---

## ADR-P09-04 — Preserve the null-amount asymmetry between the two refund paths

**Context.** `PaymentService::refund()` treats a null amount as a full refund.
`RefundService::initiateRefund()` does not — its amount is always supplied. A shared helper could
easily have normalised this.

**Decision.** `Money::isFullRefund()` returns `true` for null, with a docblock stating that only the
gateway-refund path can supply null. The asymmetry is preserved, not removed.

**Rejected.** Making null always mean "no amount" or always mean "full".

**Why not.** The two paths are genuinely different operations, and normalising them would change what
one of them does with an absent amount. Documenting the asymmetry is more honest than hiding it behind
a shared name that implies identical semantics.

**Consequence.** `MoneyTest::test_is_full_refund_treats_null_as_full_only_for_gateway_path` pins the
behaviour and forces any future change to update the test deliberately.

---

## ADR-P09-05 — Keep the unrouted legacy refund path

**Context.** `PaymentController::refund()` has no route. It is dead code with a materially different
implementation from the live admin refund: no `refunds` row, no `PaymentLog`, optional amount.

**Decision.** Preserve it unchanged. Route it through `RefundPaymentUseCase` for symmetry, but do not
delete it and do not merge the two implementations.

**Rejected.** (a) Delete the dead method. (b) Make the live path delegate to it, or vice versa.

**Why not.** (a) is a behaviour change if any route is ever wired to it, and it destroys the record of
a divergent implementation. (b) forces a choice on four observable differences — the `refunds` row, the
`PaymentLog`, the amount requirement, and the gateway payload key. Those are product decisions.

**Consequence.** A known dead path remains. It is documented in `phase-0.10-refund-map.json` with its
four behavioural differences, ready for a dedicated cleanup decision.

---

## ADR-P09-06 — Do not merge the two offline-payment paths

**Context.** `BookingController::markManualPayment()` and
`ManualBookingService::recordOfflinePayment()` both record an offline payment, and they diverge in
status handling, event dispatch, and referral attribution.

**Decision.** Wire only the admin path to `CreateOfflinePaymentUseCase`. Leave the controller method
untouched and document the divergence.

**Rejected.** Unifying them behind the use case.

**Why not.** Unification requires choosing a winner for each divergence, and each choice changes
behaviour for real offline bookings. It is a redesign, not an extraction.

**Consequence.** Two offline paths remain. Only one is behind a boundary, and the map records why.

---

## ADR-P09-07 — `PaymentStatus` is a constant alias, not an enum

**Context.** Payment status exists as two overlapping constant sets on `App\Models\Payment`. The
natural "clean up" move is a backed enum with a state machine.

**Decision.** Add `App\Domain\Payment\PaymentStatus` as read-only constant aliases over the model.
No enum, no transition validation, no state machine. The model remains the source of truth for values.

**Rejected.** A `PaymentStatus` backed enum with a `canTransitionTo()` guard.

**Why not.** The two vocabularies overlap only on `'pending'`; they are not two names for one state
machine. An enum would force a choice — rename `paid` to `completed`? map both onto one case? — and
either choice breaks persisted data or breaks a flow. A state machine would also reject transitions
that the current code legitimately performs.

**Consequence.** No clean-up of the vocabulary. The overlap is instead pinned by a test that asserts
the two sets are *partially* distinct, so a future consolidation has to be deliberate.

---

## ADR-P10-01 — `RevenueAllocation` preserves independent per-share rounding

**Context.** Each share is computed from the *already-rounded* per-screen gross, and the two shares
round independently. So `platform_share + provider_share` can differ from `gross_revenue` by up to
0.01.

**Decision.** Preserve exactly. Document the drift as a property of the existing ledger.

**Rejected.** Compute the provider share as `gross - platform_share`, so the shares always sum to
the gross.

**Why not.** That is arithmetically tidier and financially wrong for this system. Every already-settled
amount in `screen_provider_settlements` was computed with the current rule. Changing it would make
re-settled periods disagree with settled ones, destroying the audit trail. The drift is at most one
paisa and is a *reconciliation* concern, not a calculation concern.

**Consequence.** A sub-paisa reconciliation difference is possible and permanent. It is documented in
`RevenueAllocation` and pinned by `test_rounding_drift_is_preserved_not_corrected`.

---

## ADR-P10-02 — Use cases delegate to the legacy services (transitional debt)

**Context.** The Financial and Payment use cases could have absorbed the logic from the services, or
they could delegate to them as they do now.

**Decision.** Delegate. Document the transitional edge on every use case class.

**Rejected.** Moving the service bodies into the use cases.

**Why not.** Settlement, commission and referral logic totals roughly a thousand lines with
attentive transaction and lock placement. Rewriting that inside a phase whose contract is
"change no behaviour" maximises the chance of a silent change. Delegation delivers the boundary value
that actually matters — the HTTP layer no longer reaches services, and nothing outside infrastructure
reaches a vendor SDK — while leaving the risky rewrite for a phase that can afford to be careful.

**Consequence.** The application layer still depends on the service layer. This is visible,
catalogued in the dependency diff, and mechanical to remove later.

---

## ADR-P10-03 — Settlements sum snapshots, never live percentages

**Context.** A settlement could be computed from the current `screens` and `screen_providers`
percentages. It is instead computed from frozen `booking_screens` snapshots.

**Decision.** Keep it. Pin it with a test that sets live percentages to a value contradicting the
snapshot and asserts the snapshot wins.

**Rejected.** Recomputing from live data, or adding a reconciliation report that flags the difference.

**Why not.** Live percentages change. A settlement generated today for a period that ran last quarter
would silently change value depending on when it was generated. Snapshots are the only way a settlement
is reproducible, and reproducibility of a financial record is non-negotiable.

**Consequence.** Provider percentage corrections do not retroactively affect unsettled snapshots. If a
correction must apply retroactively, that is a deliberate adjustment, not a recalculation.

---

## ADR-P10-04 — Accept the `ReferralTrackingService` → application-layer dependency

**Context.** The referral revenue backfill is reused in three places, so the service now calls
`DistributeBookingRevenueUseCase` — a service depending on the application layer, which inverts the
normal direction.

**Decision.** Accept and document.

**Rejected.** (a) Have each of the three callers invoke distribution themselves. (b) Pass a callback
into the service. (c) Duplicate the distribution call inside the service.

**Why not.** (a) changes control flow in the payment verification transaction. (b) introduces a
callback interface that is harder to follow than the call it replaces. (c) reintroduces a direct
service dependency and loses the boundary.

**Consequence.** One inverted edge, isolated to a single call site and documented on the dependency
diff. The alternative — a follow-up phase restructuring the backfill — is a legitimate cleanup.

---

## ADR-P10-05 — Leave the settlement lifecycle on `SettlementService`

**Context.** Settlement has a generation path and a lifecycle (approve / mark paid / cancel). Phase
0.10 moved generation behind a use case but not the lifecycle.

**Decision.** Leave the lifecycle methods on `SettlementService`, called directly by the controller.

**Rejected.** Moving the lifecycle behind a use case as well, for symmetry with generation.

**Why not.** The lifecycle is a cohesive state machine with its own guards, and it is already
separated from generation. Wrapping it adds an indirection layer with no boundary benefit, and
generation is the path that actually needed isolating because it contains the complex aggregate read.

**Consequence.** `AdminSettlementController` depends on the use case for `store()` and on the service
for the other three actions. That asymmetry is intentional and documented on the controller.

---

## ADR-P10-06 — Leave `RevenueSplitResolverService` unextracted

**Context.** Split precedence resolution (Illuvision → duration override → screen override → provider
default → system default) is a financial rule living in `app/Services`.

**Decision.** Do not move it into `Domain\Financial` in this phase.

**Rejected.** Extracting it alongside `RevenueAllocation`.

**Why not.** It is already an isolated, well-tested rule set with its own dedicated coverage. Moving it
would mean moving its dependencies — duration overrides, screen rules, provider defaults — and
`RevenueAllocation` is the part that genuinely needed a single audited home because it is arithmetic
with no I/O. Splitting the two concerns in the same phase would have widened the blast radius for no
boundary gain.

**Consequence.** One financial rule set remains in the service layer. Recorded as deferred in the
migration ledger.

---

## Decision summary

| ADR | Decision | Type |
|---|---|---|
| P09-01 | Inject the gateway contract; keep the service body | Fix |
| P09-02 | Reproduce legacy money arithmetic exactly | Constraint |
| P09-03 | Accept Payment → Financial dependency | Accept |
| P09-04 | Preserve the refund null-amount asymmetry | Preserve |
| P09-05 | Keep the unrouted legacy refund path | Defer |
| P09-06 | Do not merge the offline-payment paths | Defer |
| P09-07 | `PaymentStatus` is a constant alias, not an enum | Constraint |
| P10-01 | Preserve independent per-share rounding | Preserve |
| P10-02 | Use cases delegate to legacy services | Transitional |
| P10-03 | Settlements sum snapshots, never live percentages | Constraint |
| P10-04 | Accept the service → application-layer edge | Accept |
| P10-05 | Leave the settlement lifecycle on the service | Defer |
| P10-06 | Leave the split resolver unextracted | Defer |

**Zero schema changes. Zero API changes. Zero status-string changes. Zero monetary-value changes.**
