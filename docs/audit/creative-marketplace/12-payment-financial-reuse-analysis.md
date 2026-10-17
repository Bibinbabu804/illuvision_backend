# 12 — Payment and Financial Architecture: Reuse Analysis for Creative Marketplace

**Generated:** 2026-09-26
**Audience:** senior engineer / project architect. This document is the financial half of the Creative Marketplace audit.
**Classification:** `[CURRENT FACT]` for everything verified in source, with `file:line`. `[ARCHITECTURE DOCUMENT]` for rules from `docs/architecture/ARCHITECTURE_RULES.md` and root `AGENTS.md`. `[INFERENCE]`, `[RECOMMENDATION]`, `[OPEN DECISION]` as defined in artifact 01.
**Status:** ANALYSIS ONLY. No financial code, migration, table, route or test was modified. No Creative financial record exists.

---

## 1. Executive answer to the question in the brief

> *"Determine whether Creator Earnings should reuse existing settlement infrastructure, reuse financial primitives, have a separate Creative earnings domain, or require a new financial boundary. Do not decide without evidence."*

`[RECOMMENDATION]` **The evidence supports a separate Creative earnings domain built on shared financial primitives, and explicitly rejects reusing the settlement infrastructure as it exists today.** The three-part answer, with what is still open:

| Question | Evidence-based answer | Confidence |
|---|---|---|
| Reuse the **payment gateway**? | **Yes — reuse the existing contract unchanged.** `PaymentGatewayInterface` is already domain-owned, SDK-free and booking-agnostic. | High — `[CURRENT FACT]` |
| Reuse the **payment record** (`payments`)? | **No.** `payments.booking_id` is `NOT NULL ON DELETE CASCADE` and `PaymentService` couples confirmation to a booking status transition. Creative funding is a new table. | High — `[CURRENT FACT]` |
| Reuse the **settlement service** (`SettlementService`)? | **No, not as it stands.** It is screen-provider- and period-bound, sources everything from `booking_screens` revenue snapshots, and its `approveSettlement` / `markAsPaid` transitions take **neither a transaction nor a row lock**. Its *table shape* is the right template. | High — `[CURRENT FACT]` |
| Reuse the **financial primitives**? | **Yes**: `decimal(12,2)` persistence, minor-unit conversion at the gateway boundary, the append-only log discipline, the snapshot-immutability discipline, and the pessimistic-lock discipline demonstrated in `InventoryService` and the partner-commission repository. | High |
| A **separate Creative earnings domain**? | **Yes.** No existing table can hold it, and no existing commission rule is provider-agnostic. | High |
| A **new financial boundary** (new contracts/adapters)? | **No.** `PaymentGatewayInterface` and the exchange-rate client already are that boundary. Adding a second would violate Rule 3 and Rule 7. | High |
| **Commission rate, accrual trigger, payout cadence, payout provider, tax, currency** | **Cannot be determined from the source.** | `[OPEN DECISION]` |

---

## 2. What exists today

### 2.1 The gateway abstraction — the one genuinely reusable financial component

`[CURRENT FACT]` `app/Domain/Payment/Contracts/PaymentGatewayInterface.php` declares exactly four operations:

```text
createOrder(array $params): array                     line 19
verifySignature(string $orderId, string $paymentId, string $signature): bool   line 29
fetchPayment(string $paymentId): array                line 37
refund(string $paymentId, ?int $amountInPaise = null, array $notes = []): array line 47
```

`[CURRENT FACT]` The only implementation is `App\Infrastructure\Payments\RazorpayPaymentGateway`. `[ARCHITECTURE DOCUMENT]` Rule 3 places vendor SDK types here and forbids them leaking into Domain or Application signatures. The interface takes scalars and arrays — no `Razorpay\Api\*` type appears in it.

`[RECOMMENDATION]` This is the correct boundary and Creative reuses it verbatim. A Creative funding use case depends on the **interface**; it never imports `RazorpayPaymentGateway` and never sees a Razorpay type.

### 2.2 The payment workflow service — advertising-bound, legacy by its own documentation

`[CURRENT FACT]` `app/Services/PaymentService.php` (221 LOC) is documented in its own class docblock as a *LEGACY SERVICE — retained as the transaction owner of the payment workflows (Architecture Rule 7: strangler pattern)*. Its public surface:

```text
createOrder(Booking $booking): Payment                 line 46
verifySignature(...)                                   line 77
verifyAndConfirmPayment(...)                           line 98
fetchPayment(string $razorpayPaymentId): array         line 175
refund(Payment $payment, ?float $amount = null): Payment  line 187
```

`[CURRENT FACT]` Three consequences for Creative:

1. `createOrder()` is **typed to `Booking`** and writes a receipt and notes built from booking fields (`'booking_id' => $booking->id`, `'campaign_title' => ...`). A creative funding intent is not expressible through it.
2. `verifyAndConfirmPayment()` transitions a **booking status** as part of confirmation. Creative funding confirmation must not transition a booking.
3. `refund()` accepts `?float $amount` — a float crosses a service boundary. Creative must not follow this shape.

### 2.3 The money representation — precise statement of what `Money` is and is not

`[CURRENT FACT]` `app/Domain/Payment/Support/Money.php` is documented as *"an **extraction**, not a redesign"* reproducing the exact legacy arithmetic:

```text
rupees -> paise : (int) round($rupees * 100)
paise  -> rupees: round($paise / 100, 2)
```

`[CURRENT FACT]` It exposes `MINOR_UNITS_PER_MAJOR = 100` and `GATEWAY_CURRENCY = 'INR'`, and its own docblock states: *"No float rounding mode, no BCMath, and no currency conversion is introduced here. `float` is NOT used for persistence — persistence continues to use the `decimal:2` Eloquent cast."*

**So, precisely:**

| It **is** | It **is not** |
|---|---|
| A pure, deterministic **minor-unit conversion helper** at the gateway boundary | A money **value object** (no amount + currency, no arithmetic, no validation, no immutability guarantees) |
| The single audited place where rupee↔paise conversion happens | A **currency conversion** facility — `GATEWAY_CURRENCY` is hard-coded to INR and there is no FX here |
| A correct example of Rule 3 (vendor-facing conversion isolated in one place) | Safe to use as a Creative money type across layers |

`[RECOMMENDATION]` Creative uses `Money` only where it calls the gateway (converting a `decimal(12,2)` rupee amount into paise). It does **not** use `Money` as its money type inside the domain. Whether Creative introduces a proper `Money` value object, or the shared one is upgraded, is `[OPEN DECISION]` and must not be settled inside a Creative feature.

### 2.4 The financial records

`[CURRENT FACT]` The advertising financial schema, from artifact 08:

| Table | Role | Creative verdict |
|---|---|---|
| `payments` | Payment record; `booking_id NOT NULL ON DELETE CASCADE`; Razorpay + offline direct | **Do not reuse, do not extend, do not relax** |
| `payment_logs` | Append-only payment event log | **Pattern** for a `creative_funding_logs` |
| `refunds` | Refund ledger rows | **Pattern** for `creative_refunds` |
| `booking_pricing_breakdowns` | Immutable per-screen-per-day financial snapshot | **The canonical immutability precedent** |
| `booking_screens` | Gross + both shares + **both percentages**, snapshotted at booking creation | **The canonical auditable-split precedent** |
| `partner_commissions` | Commission payable, lifecycle pending → approved → paid / cancelled, pessimistic lock | **Lifecycle + locking precedent** for creator earnings |
| `screen_provider_settlements` | Settlement period, reference number, generated/approved/paid, three actor FKs, **no currency column** | **Payout-batch structure precedent** |
| `referral_usages` | Duplicated profit snapshot across two tables, deliberately, for auditability | **Redundancy principle precedent** |
| `currency_rates` | FX rates | **Shared** if Creative is ever multi-currency |

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §10: financial history is immutable; fix forward with compensating records, never rewrite a stored fact.

### 2.5 The absence of a webhook — a first-class finding

`[CURRENT FACT]` There is **no payment webhook route** in this application. Verification is client-initiated through `POST /api/payments/razorpay/verify` (`routes/api.php:303`). There is no signature-validation helper, no replay protection, no webhook event store, and no `afterCommit` mechanism anywhere (`0` occurrences of `afterCommit` or `ShouldHandleEventsAfterCommit`).

`[INFERENCE]` A client that abandons the callback leaves a payment created at the gateway with no local confirmation. That is the existing advertising behaviour and is **preserved** — `AGENTS.md` §3 forbids changing it incidentally.

`[OPEN DECISION]` Whether Creative funding may rely on the same client-initiated verification, or must adopt a webhook with signature validation, replay rejection and idempotent handling, is a Creative design decision. A webhook endpoint added without those three controls would be a P0 defect (artifact 19).

### 2.6 Verified financial defects and gaps, recorded not fixed

`[CURRENT FACT]` Found while auditing, and explicitly **not** changed by this audit (`AGENTS.md` §18):

| Finding | Evidence | Why it matters to Creative |
|---|---|---|
| Gateway call inside a database transaction | `app/Services/RefundService.php:51` — `DB::transaction(function () { ... $this->gateway->refund(...) })` | Direct violation of Rule 4. Creative must not copy it. |
| Settlement approval and payment take no transaction and no lock | `SettlementService::approveSettlement`, `SettlementService::markAsPaid` | A Creative payout approval that copies this shape is racy. |
| Partner commission lock lives in the repository, not the service | `EloquentPartnerCommissionRepository.php:31` (Phase 0.10 extraction); `CommissionService.php:19` documents the legacy call only | The "lock the payout row" precedent is real, but it is one repository method, not a service-wide discipline. |
| Float crosses a service boundary | `PaymentService::refund(Payment $payment, ?float $amount = null)` | Creative must not accept a float amount. |
| No commission percentage is defined for Creative | Nothing in source | The existing `10.00 / 90.00` defaults on `screens` and `screen_providers` are **provider** commercial terms and must not be inherited. |
| No payout processor exists | No adapter, no API client, nothing | Manual admin batch (as providers get today) is the only evidenced path. Anything else is new infrastructure. |

---

## 3. The three-way separation

### 3.1 ADVERTISING FINANCIAL DOMAIN — owns its records, unchanged

`[RECOMMENDATION]` Owns, and continues to own, with no Creative involvement:

```text
payments, payment_logs, refunds
booking_pricing_breakdowns, booking_screens
screen_providers.illuvision_percentage / screen_provider_percentage
CampaignPricingEngine and the whole pricing rule set
SettlementService / screen_provider_settlements
RevenueDistributionService / RevenueSplitResolverService
CommissionService / partner_commissions / referral_usages
```

`[ARCHITECTURE DOCUMENT]` Rule 5: none of their routes, request fields, response shapes, status codes or error envelopes may change as a consequence of Creative work.

### 3.2 CREATIVE FINANCIAL DOMAIN — proposed, all new

`[RECOMMENDATION]` New tables (authoritative design in artifact 09):

```text
creative_fundings          a funding transaction for a creative project
creative_funding_logs      append-only funding event log
creative_refunds           refunds for a creative project
creator_earnings           an immutable financial fact: an amount earned from a specific project
creator_payouts            a payout batch moving approved earnings to a creator
creator_payout_items       the earnings included in a payout
```

`[RECOMMENDATION]` The two invariants that make this a *domain* and not a table set:

1. **Agreed terms are frozen.** At proposal acceptance the amount, the split percentages, any tax and fee, and the FX rate (if used) are stored together. Nothing recalculates them afterwards. This is the `booking_screens` property: the stored percentage is the percentage that produced the stored amount, so the split is auditable from the record alone.
2. **Earnings are immutable facts.** A rejected delivery does not decrement an earning; it creates a reversing entry. A cancelled project does not mutate a payout; it creates a compensating record.

### 3.3 SHARED FINANCIAL INFRASTRUCTURE — reuse, never duplicate

`[RECOMMENDATION]` The genuinely shared surface is small and already exists:

```text
PaymentGatewayInterface + RazorpayPaymentGateway   gateway contract and its only adapter
Money                                              minor-unit conversion at the gateway boundary only
config/filesystems.php                             no - not financial
currency_rates + ExchangeRateService               FX, if Creative is ever multi-currency
decimal(12,2) persistence convention               the repository's money representation
payment_logs / booking_pricing_breakdowns          the DISCIPLINES (append-only, immutable snapshot)
```

`[ARCHITECTURE DOCUMENT]` Rule 3 forbids a second vendor encapsulation; Rule 7 forbids copying business logic; `AGENTS.md` §10 forbids a second exchange-rate implementation.

---

## 4. Do-not-reuse list (financial)

| Component | Why not | What to do instead |
|---|---|---|
| `payments` table | `booking_id NOT NULL ON DELETE CASCADE`; confirmation transitions a booking; relaxing it alters an existing financial table and every booking payment query | `creative_fundings` over the **existing gateway contract** |
| `PaymentService` | Typed to `Booking`; couples confirmation to a booking status transition; documents itself as a legacy strangler artifact | A new Creative funding use case depending on `PaymentGatewayInterface` |
| `refunds` table | Advertising-bound ledger | `creative_refunds` mirroring the shape |
| `SettlementService` | Screen-provider and period-bound; sources from booking revenue snapshots; approval/paid transitions take no transaction and no lock | New Creative payout use case; copy the **payout-batch table shape**, not the service |
| `screen_provider_settlements` | No currency column — copying the shape silently assumes single currency | `creator_payouts` **with** explicit currency columns |
| `CommissionService` | Partner-bound columns; its lock now lives in a repository method | Copy the lifecycle; build a Creative earning service **with** its own lock |
| `partner_commissions` | Affiliate attribution columns | `creator_earnings` |
| `screens.illuvision_percentage` / `screen_providers.screen_provider_percentage` (10.00 / 90.00) | Provider commercial terms | `[OPEN DECISION]` — no percentage may be inherited or invented |
| `RewardAccount` / `RewardTransaction` | **Integer points** ledger tied to bookings | Copy the **shape** with `decimal(12,2)` + currency |
| `PricingService` / `CampaignPricingEngine` / `Pricing\*` | Media-time pricing; several operate on float totals | A small deterministic Creative quote calculation, frozen at acceptance |
| `Money` as a money *type* | It is a conversion helper, not a value object | `decimal(12,2)` in the domain; `Money` only at the gateway boundary |

---

## 5. Financial safety constraints for Creative

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §10 and Rule 4, restated as Creative-specific obligations:

1. **Server-derived amounts.** A proposal amount, a project total, a commission, an earning and a payout total are all computed server-side. A client may send scope, duration and display preferences — never an amount. This is the single highest-severity Creative risk (artifact 19).
2. **No float money.** `decimal(12,2)` in the database; no float in a layer-crossing signature. Follow the existing money representation and rounding convention exactly, and do not migrate the representation as part of a Creative change.
3. **Immutable financial history.** Written once. Corrections are new rows.
4. **Explicit transaction boundary.** Every handler that writes money or changes a lifecycle state opens `DB::transaction(...)` explicitly.
5. **No network call inside a lock.** The Razorpay call, any FX fetch performed during a financial write, and any third-party notification happen outside the row lock. `RefundService.php:51` is the counter-example.
6. **Lock and re-check for every contested transition.** Proposal acceptance, funding confirmation, delivery acceptance, review submission, earning approval, payout processing: `check → DB::transaction → lockForUpdate → re-check inside the transaction → mutate → commit`.
7. **Duplicate-request protection by constraint where possible.** `UNIQUE` on `creator_payout_items.earning_id` is the hard guarantee that an earning is never paid twice; an application check is not.
8. **Snapshot the split, not only the totals.** Store the amount *and* the percentages (and FX rate) that produced it.
9. **Rounding follows the existing convention.** `(int) round($rupees * 100)` for paise and `round($paise / 100, 2)` back, as `Money` documents. Creative must not introduce a different rounding mode.
10. **Audit every financial transition.** Each acceptance, funding, refund, earning, approval and payout writes an `activity_logs` row plus a table-local append-only log.

---

## 6. Mandatory regression suites for any Creative financial work

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §10 and §17. These must be run before and after, and their outcomes reported exactly:

```text
tests/Feature/Golden/GoldenFinancialRegressionTest.php
tests/Feature/Golden/GoldenPricingRegressionTest.php
tests/Feature/Golden/GoldenPaymentRegressionTest.php
tests/Feature/Golden/GoldenFinancialBoundaryTest.php
tests/Feature/Golden/GoldenPaymentBoundaryTest.php
tests/Feature/FinancialRegressionAndHardeningTest.php
tests/Feature/Golden/GoldenConcurrencyVerificationTest.php
tests/Feature/BookingConcurrencyTest.php
tests/Unit/Architecture/ArchitectureRuleTest.php
```

Reference state, re-measured 2026-09-26 by this audit: full suite **673 passed, 2 failed, 1 warning, 4 skipped, 5564 assertions**; the 2 failures are the pre-existing message-wording drift in `tests/Feature/BookingConcurrencyTest.php:371` and `tests/Feature/CampaignBookingPricingIntegrityTest.php:228`; `ArchitectureRuleTest` 14 passed. A Creative change that alters any of these outcomes is a reportable regression, not an improvement.

---

## 7. Open decisions (financial)

| ID | Question | Missing information | Blocks |
|---|---|---|---|
| OD-TC-02 | Is a proposal amount server-derived or creator-proposed? | Product decision. The recommendation is server-derived; a creator-proposed amount with a server-computed comparison is a different design. | C5 |
| OD-TC-03 | Does funding precede or follow project creation? | Product decision with financial consequence (authorisation, refund-if-not-created). | C6 |
| OD-TC-04 | Is escrow / milestone funding in scope? | Product decision. Determines whether funding needs tranches and a held balance. | C6 |
| OD-TC-08 | Which event accrues an earning, and does a dispute window exist? | Product decision. Determines the earning status vocabulary and whether a settlement period is needed at all. | C9/C10 |
| OD-TC-09 | Is commission on gross or net? | Commercial decision. Determines what the stored percentage applies to. | C10 |
| OD-TC-12 | Are tax and third-party costs (stock, licensing) in scope? | Commercial/tax decision. Determines the `AGREED TERMS` invariant formula. | C6 |
| OD-TC-13 | Multi-currency or INR only? | Product decision. Note `screen_provider_settlements` has **no** currency column, so there is no precedent to copy. | C6 |
| OD-TC-14 | Where does the commission rate come from, and is it snapshotted or read at payout? | Commercial decision. The recommendation is a versioned Creative table snapshotted into the project terms, never `settings`. | C10 |
| OD-TC-15 | Is a payout a gateway disbursement or a recorded manual transfer? | Product/operational decision. No payout-processor adapter exists in this repository. | C10 |
| OD-TC-16 | Settlement period, payout cadence and threshold? | Product decision. | C10 |
| OD-TC-17 | Where does Creative transaction orchestration live? | Architecture decision between `app/Services/Creative` and a new layer. The recommendation follows the existing convention: `app/Services/Creative`, because `app/Application/` is a layer under retirement. | C6 |
| OD-TC-07 | Is a gateway webhook in scope for Creative, and with what signature / replay / idempotency contract? | Architecture + security decision. | C6 |
| OD-MONEY | Does Creative introduce a money value object, or is the shared `Money` helper upgraded? | Architecture decision. It must not be decided by a Creative feature. | C5/C6 |
| OD-COMMISSION | Who approves an earning, and who may request a payout? | Governance decision. Verified constraint: no `finance` role exists today, and `settlements.view` / `.approve` / `.pay` are used on 12 live routes and **granted to no role** (artifact 13), so separation of duties is currently unenforceable. | C10 |

---

## 8. Final statement for the project architect

`[RECOMMENDATION]` Creative Marketplace should:

- **reuse** `PaymentGatewayInterface` and the exchange-rate infrastructure unchanged;
- **own** its funding, refund, earning and payout records, in new tables, with frozen terms and immutable facts;
- **copy three disciplines** from the existing system and nothing else: the auditable-split snapshot (`booking_screens`), the append-only financial log (`payment_logs`), and the locked payout transition (partner-commission repository);
- **refuse** both failure modes this document identifies — a parallel payment stack, and being forced into booking-specific financial logic;
- **not decide** any rate, trigger, cadence, tax or currency question until the product owner answers. Every one of those is listed in §7 with the exact missing information.

No financial behaviour in the advertising marketplace changes as a result of this audit. Nothing in this document has been implemented.
