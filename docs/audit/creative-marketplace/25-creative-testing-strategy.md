# 25 — Creative Marketplace Testing Strategy

**Generated:** 2026-09-26
**Classification:** `[CURRENT FACT]` for the existing test infrastructure, verified by reading the test tree. `[RECOMMENDATION]` for the Creative test plan. `[OPEN DECISION]` where a test cannot be specified until a business rule is decided.
**Status:** PLAN ONLY. No Creative test exists; no existing test was modified.

---

## 1. What already exists and is directly reusable

`[CURRENT FACT]` Measured 2026-09-26. **85 test files**, one MySQL testing database (`db_illuvision_testing`), `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, 68 test files using `RefreshDatabase` or `DatabaseTransactions`.

| Existing asset | Location | Creative reuse |
|---|---|---|
| Architecture guard | `tests/Unit/Architecture/ArchitectureRuleTest.php` (14 tests) | **Copy the enforcement technique, not the rules.** Add Creative cases (see §3.1) |
| Integration boundary guard | `tests/Feature/Integrations/IntegrationBoundaryTest.php` (+ `ImmediateTestMail.php`, `QueuedTestMail.php`) | **Reuse directly** — it already proves the integration boundary and provides a queued-mail test double |
| Golden API contract | `tests/Feature/Golden/GoldenApiContractRegressionTest.php` | **Must stay green.** Add Creative routes to its expectations additively |
| Golden financial suites | `GoldenFinancialRegressionTest`, `GoldenPricingRegressionTest`, `GoldenPaymentRegressionTest`, `GoldenFinancialBoundaryTest`, `GoldenPaymentBoundaryTest`, `tests/Feature/FinancialRegressionAndHardeningTest.php` | **Must stay green.** They prove Creative did not disturb advertising money |
| Golden booking / availability | `GoldenBookingRegressionTest`, `GoldenAvailabilityRegressionTest` | **Must stay green** |
| Concurrency verification | `tests/Feature/Golden/GoldenConcurrencyVerificationTest.php`, `tests/Feature/BookingConcurrencyTest.php` | **Reuse the technique** (see §3.6) |
| Security regression | `tests/Feature/Security/` (5 files) + `tests/Feature/AuthorizationHardeningTest.php` | **Reuse the pattern.** `Sec03PermissionVocabularyTest` is directly relevant: it pins that every permission used on a route is seeded — the exact guard Creative permissions need |
| Golden fixture builder | `tests/Feature/Golden/GoldenTestSetupTrait.php` | **Reuse the seeding style** for Creative fixtures; do not modify it — add a `CreativeTestSetupTrait` instead |
| Domain unit tests | `tests/Unit/Domain/{Pricing,Payment,Financial}`, `tests/Unit/Application/{Pricing,Payment}`, `tests/Unit/Infrastructure/{Auth,ExchangeRates,Payments}` | **Direct pattern precedent** for Creative unit tests |
| Reporting test | `tests/Unit/Reporting/ReportingSupportTest.php` | Pattern for Creative reporting tests |
| Factories | `database/factories/UserFactory.php`, `AssetFactory.php` | Reuse `UserFactory` for the user anchor; **new** factories needed for every Creative aggregate |
| Test credentials | `tests/TestCase.php` loads an optional git-ignored `.env.testing.local` | Reuse; do not reintroduce committed credentials |

### What must **not** be reused

`[CURRENT FACT]` `tests/compressed/` contains three stray `.webp` artefacts (`1790420831_ylA6TfVV18.webp`, `1790422122_YZ3nqjwIem.webp`, `1790422567_UXN3wWRFhi.webp`) committed by a test run. `[RECOMMENDATION]` Treat test output in the repository as a defect to report, and make sure Creative image tests write to a temporary path instead. Do not clean these up as part of a Creative task; report them.

---

## 2. The pre-existing baseline every Creative task must preserve

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §17. Run the full suite before and after; if any of these outcomes changes, it is a **reportable regression**, not an improvement:

```text
Full suite            673 passed, 2 failed, 1 warning, 4 skipped, 5564 assertions
Failure 1             tests/Feature/BookingConcurrencyTest.php
                      expects "no longer available"; actual "... has only N units available, but M requested."
Failure 2             tests/Feature/CampaignBookingPricingIntegrityTest.php  (same wording drift)
Warning               MediaHelperTest tautological assertion
ArchitectureRuleTest  14 passed (PHPUnit deprecations)
route:list            385 routes (Creative ADDS routes; it never changes an existing one)
pint --test           fails repository-wide (pre-existing)
```

`[RECOMMENDATION]` The Creative task report must state the exact command, the exact counts, and explicitly label the 2 failures and 1 warning as pre-existing.

---

## 3. The Creative test matrix

### 3.1 Architecture / boundary tests — `tests/Unit/Architecture/CreativeBoundaryTest.php`

`[RECOMMENDATION]` Written in **Phase C1**, before any Creative behaviour exists, so the boundary is enforced from the first commit. Use the token-stream approach already proven in `ArchitectureRuleTest`.

| Assertion | Fails if |
|---|---|
| No `App\Http` import under `app/Domain/Creative` | A domain class reaches for a request or controller |
| No `App\Services` import under `app/Domain/Creative` | Rule 11 erosion (7 pinned advertising violations are **not** a precedent) |
| No `App\Models` import under `app/Domain/Creative` | An aggregate root reaches for another model |
| No `Http::`, `Mail::`, `Storage::`, `curl_`, `Razorpay\Api` under `app/Domain/Creative` | Rules 1, 2, 3 violations |
| No `app(` / `resolve(` in `app/Domain/Creative` or `app/Services/Creative` | Service-locator debt (11 existing sites are not a precedent) |
| No `App\Infrastructure` import from `app/Domain/Creative` | Rule 11 direction inversion |
| No umbrella directory (`Common`, `Utils`, `Managers`, `SharedLogic`, `Components`) under `app/Domain/Creative` | `AGENTS.md` §7 violation |
| Every Creative model lives under `app/Domain/Creative` (none under `app/Models`) | Boundary erosion by convenience |

`[RECOMMENDATION]` Prove the test can fail: during development, add a deliberately illegal import, watch it fail, then remove it. A guard that has never failed is not a guard.

### 3.2 Domain unit tests — `tests/Unit/Domain/Creative/`

No database, no HTTP, no container. Deterministic and fast.

| Test | Asserts |
|---|---|
| `CreatorStatusTest` | Every legal transition; every illegal transition throws |
| `ProposalQuoteCalculatorTest` | **Identical inputs produce an identical quote** (the Creative analogue of Rule 9 determinism) |
| `ProposalQuoteCalculatorTest` | A client-supplied amount is not an input |
| `AgreedTermsTest` | `total == sum(lines) + tax + fee` holds and is violated when tampered |
| `RevisionLimitPolicyTest` | The limit is enforced **without a database**; over-limit behaviour is the approved rule, not a guess |
| `EarningAccrualTest` | Accrual from an engagement history is reproducible and never recalculates a stored amount |
| `PayoutBatchTest` | An earning can appear in at most one payout; the batch total equals the item sum |
| Value objects | `RateCard`, `ProposalQuote`, `FileVisibility`, `MoneyAmount` reject invalid states in the constructor |

### 3.3 Application / use-case tests — `tests/Unit/Application/Creative/`

Follow the existing `tests/Unit/Application/{Pricing,Payment}` precedent: real services, bound repositories, no HTTP.

| Test | Asserts |
|---|---|
| `AcceptProposalHandlerTest` | One project created; siblings declined; terms frozen; audit written |
| `AcceptProposalHandlerTest` (concurrency-shaped) | With a stale in-memory proposal, the handler re-reads inside the transaction and refuses |
| `FundProjectHandlerTest` | Funding row + append-only log; gateway call delegated to the contract, never to a real network |
| `SubmitDeliveryHandlerTest` | Only the project creator; files recorded; delivery cannot be submitted twice |
| `AcceptDeliveryHandlerTest` | Only the paying client; cannot accept an already-accepted delivery |
| `SubmitReviewHandlerTest` | Only the client; not before acceptance; **immutable after write** |
| `AccrueCreatorEarningHandlerTest` | Correct accrual from a real project; idempotent on replay |
| `ProcessCreatorPayoutHandlerTest` | Batch totals; an earning already paid is refused; reversing entries, never updates |
| `CreativeFileGrantorTest` | Owner and permitted project participants are granted; everyone else is refused |

### 3.4 API contract tests — `tests/Feature/Creative/Api/`

| Test | Asserts |
|---|---|
| `CreativeApiContractTest` | For each Creative endpoint: URI, method, request fields, status codes, response envelope, pagination shape — pinned, and **additive** to `GoldenApiContractRegressionTest` |
| `CreativeResponseShapeTest` | Resources produce the documented shape; no raw model leaks (no internal column, no float money) |
| `CreativeValidationTest` | Every FormRequest rejects an over-posted field — especially `amount`, `commission`, `status`, `currency`, `creator_id` |
| `CreativeRouteParityTest` | `route:list` count = 385 + the number of Creative routes; no existing route changed (method, URI, middleware, name) |
| Public surface test | The catalogue endpoints are readable unauthenticated; everything else returns 401 |

### 3.5 Authorization tests — `tests/Feature/Creative/Authorization/`

`[RECOMMENDATION]` Every one of these must assert **both** the allowed and the denied path, and must include a privilege-escalation case.

| Test | Asserts |
|---|---|
| `CreativeRouteAuthorizationTest` | Every Creative route requires authentication (401) and the right permission (403) |
| `CreatorPermissionVocabularyTest` | Every `creative.*` permission used on a route exists as a `role_permissions` row for `creator` — the `Sec03PermissionVocabularyTest` pattern |
| `BriefOwnershipTest` | Author-only read/edit; another client gets 403 |
| `ProjectParticipantTest` | Client and awarded creator only; a third party gets 403 |
| `EarningsIsolationTest` | A creator sees only their own earnings — asserted at the **route** and in the **query** (a route-only check that returns another creator's data through a filter gap must fail) |
| `PayoutAdminTest` | Only the settlement/finance permission can approve an earning or create a payout |
| `SuperAdminAsymmetryTest` | The middleware bypass still works, and `RolePermissionChecker` still has **no** bypass — Rule 12 pinned, not changed |
| `RoleResolutionTest` | A user who is advertiser + creator resolves under both readings (depends on OD-DB-08) |
| `PrivilegeEscalationTest` | A creator cannot set `role`, `commission`, `status` or another creator's id via any Creative payload |

### 3.6 Transaction and concurrency tests

`[CURRENT FACT]` Two proven techniques exist in this repository, both real-database based:

1. `tests/Feature/BookingConcurrencyTest.php` opens a real transaction, takes `lockForUpdate()` on a row, and asserts a second transaction blocks until the first commits (`test_fleet_row_lock_blocks_second_transaction_until_first_commits`, line 260). It also interleaves writes with `usleep()` to force a genuine race (`test_true_concurrent_bookings_cannot_over_commit_fleet`, line 390).
2. `tests/Feature/Golden/GoldenConcurrencyVerificationTest.php` asserts a lock is actually emitted, asserts a **deliberate absence** of a lock where one must not appear (line 51: `Payment::where(...)->firstOrFail()` must NOT lock), and asserts a rollback reverts partial mutations (line 94).

`[RECOMMENDATION]` Creative reuses both, unchanged in spirit:

| Test | Asserts |
|---|---|
| `ProposalAcceptanceConcurrencyTest` | Two interleaved acceptances of one proposal produce exactly one project; the loser gets a domain refusal, not a duplicate project |
| `FundingConfirmationIdempotencyTest` | A repeated confirm does not create a second funding record |
| `RevisionLimitConcurrencyTest` | Two concurrent revision requests cannot exceed the limit |
| `EarningApprovalPayoutConcurrencyTest` | An earning cannot be approved twice or paid twice; the losing transaction is refused by the unique constraint or the lock |
| `CreativeRollbackTest` | Every multi-write handler rolls back completely on a mid-transaction failure (the GoldenConcurrencyVerificationTest rollback pattern) |
| `CreativeLockEmissionTest` | Each contested transition **does** emit `lockForUpdate`, and each non-contested read **does not** — the "deliberate absence" pattern, which is what stops a future developer from adding a lock where it would hold a connection |
| `NoNetworkCallInsideLockTest` | Static/structural assertion that the gateway call is not inside a `DB::transaction` that holds a lock (Rule 4) |

### 3.7 Financial tests

`[RECOMMENDATION]` Creative money gets the same treatment as advertising money, in a Creative suite plus the unchanged advertising suites as the regression net.

| Test | Asserts |
|---|---|
| `CreativeMoneyRepresentationTest` | Every monetary column is `decimal(12,2)`; no PHP float is used in a layer-crossing signature; rounding follows the existing convention exactly |
| `CreativeServerDerivedAmountTest` | A proposal amount, a project total and an earning are all computed server-side; posting an amount changes nothing |
| `CreativeSnapshotImmutabilityTest` | Accepted terms cannot be modified; a later catalogue price or FX change does not alter them; corrections are new rows |
| `CreativeSplitAuditabilityTest` | The stored percentage is the percentage that produced the stored amount — recomputable **from the record** without external config (the `booking_screens` property) |
| `CreativeGoldenCasesTest` | A fixture set of engagement scenarios with expected amounts, earnings and payout totals — the Creative analogue of `tests/audit/golden-financial-cases.json` |
| `CreativeRefundTest` | Refund creates a compensating record; the original funding row is unchanged; refund eligibility follows an approved rule, not an assumed one |
| Advertising suites unchanged | All six `Golden*` financial/payment/pricing suites plus `FinancialRegressionAndHardeningTest` remain green |

### 3.8 Database tests

| Test | Asserts |
|---|---|
| `CreativeMigrationReversibilityTest` | Each Creative migration rolls back independently, in reverse order, leaving no residue — `AGENTS.md` §14 |
| `CreativeSchemaContractTest` | Column types, indexes, unique constraints, foreign keys and `null on delete` behaviour match artifact 09 exactly |
| `CreativeUniqueConstraintTest` | One accepted project per accepted proposal; one payout item per earning; one review per project per reviewer; one file record per stored object |
| `CreativeForeignKeyTest` | No cross-database FK; no FK from Creative into advertising tables; the deferred `creative_proposals.project_id` FK is added after `creative_projects` exists |
| `CreativeNoAdvertisingTableChangeTest` | Row counts and `updated_at` in the 20+ advertising/financial tables are unchanged by a full Creative lifecycle run |
| `CreativeSoftDeleteTest` | Where soft delete is specified, a deleted Creative row is invisible to reads and retained for audit |

### 3.9 File access tests

`[RECOMMENDATION]` The highest-value new test area, because the platform has no precedent.

| Test | Asserts |
|---|---|
| `CreativeFileUploadValidationTest` | Mime type allow-list, size limit, extension; an executable or double-extension file is rejected (`FileController` has no validation today — Creative must not inherit that) |
| `CreativeFileIdorTest` | Creator A cannot read creator B's private file by identifier, by incrementing the id, or by guessing the public id — **test the enumerable and the unguessable case separately** |
| `CreativeFileVisibilityTest` | A private file is never reachable by a `public` disk URL; a public portfolio image is |
| `CreativeFileParticipantAccessTest` | Brief attachments follow the brief; delivery files follow the project's participants; a withdrawn proposal's attachments do not leak |
| `CreativeImageProcessingTest` | Compression and thumbnail generation reuse `ImageProcessingContractInterface`; a non-image is stored unchanged; output respects the size budget |
| `CreativeFileDeletionTest` | Deletion is authorised, audited, and does not orphan a referenced record |

### 3.10 Notification and event tests

`[RECOMMENDATION]` Reuse `tests/Feature/Integrations/ImmediateTestMail.php` and `QueuedTestMail.php` and the `array` mailer.

| Test | Asserts |
|---|---|
| `CreativeEventDispatchTest` | Each state transition dispatches exactly its defined event, and no other |
| `CreativeEventAfterCommitTest` | A rolled-back transaction dispatches **no** event and sends **no** mail |
| `CreativeNotificationRecipientTest` | The right parties are notified; `creator` is now a resolvable recipient; a role that should not be notified is not |
| `CreativeNotificationUsesExistingPathTest` | Dispatch goes through `NotificationService` / the existing Mailables pattern; no Creative-specific notification store exists |
| `CreativeNoDuplicateNotificationTest` | One transition, one notification |

### 3.11 State transition tests

`[RECOMMENDATION]` A data-provider matrix per aggregate, generated from the state tables in artifact 15, asserting for every `(state, event, actor)` triple: allowed or refused, plus the expected persisted state, event and notification.

| Test | Asserts |
|---|---|
| `CreatorStateMachineTest` | Registration, approval, rejection, suspension, reactivation |
| `BriefStateMachineTest` | Draft, submitted, closed |
| `ProposalStateMachineTest` | Draft, submitted, withdrawn, accepted, declined |
| `ProjectStateMachineTest` | The full lifecycle including cancellation and its financial consequence |
| `RevisionStateMachineTest` | Requested, submitted, accepted, exhausted |
| `DeliveryStateMachineTest` | Submitted, accepted, rejected |
| `ReviewStateMachineTest` | Submitted only; no transition out |
| `EarningStateMachineTest` | Accrued, held (if a dispute window exists), approved, paid, reversed |
| `IllegalTransitionTest` | Every illegal `(state, event)` pair across all aggregates is refused — a single data-provider test that closes the whole class of bug |

### 3.12 Idempotency tests

`[CURRENT FACT]` The platform has no idempotency mechanism, so these tests define Creative's own guarantees rather than reusing anything.

| Test | Asserts |
|---|---|
| `CreativeEndpointIdempotencyTest` | Repeated acceptance, funding confirmation, delivery submission, review submission and payout processing each produce exactly one effect |
| `CreativeEventReplayTest` | Re-delivering a domain event does not duplicate the effect |
| `CreativeFundingReplayTest` | A repeated gateway confirmation is a no-op, not a second funding row |
| `CreativeWebhookReadinessTest` | **Deferred with an explicit note:** if Creative adopts a webhook (OD, artifact 12), signature validation, replay rejection and idempotent handling must be tested before that route exists. A webhook endpoint without these tests is a P0 defect |

---

## 4. Test-to-phase map

| Phase | Tests that must exist before the phase is called done |
|---|---|
| C1 | `CreativeBoundaryTest` (and proven able to fail) |
| C2 | Creator state machine, file upload validation, file IDOR, file visibility, audit-written tests |
| C3 | Catalogue publish transitions, duplicate-category junction test, rate representation |
| C4 | Brief ownership, closed-brief immutability, attachment access |
| C5 | Quote determinism, quote stored-not-recomputed, client-amount-ignored, one-proposal constraint |
| C6 | **Acceptance concurrency**, agreed-terms invariant, snapshot immutability, funding idempotency, lock-emission and no-network-in-lock, money representation |
| C7 | Delivery ownership, double-accept refusal, delivered-file access |
| C8 | Revision limit (unit) + revision limit under concurrency |
| C9 | Self-review impossible, single review, immutability, `testimonials` untouched |
| C10 | Accrual determinism, immutability/reversal, double-payment refusal, commission reproducibility, earnings isolation |
| C11 | Event dispatch, after-commit behaviour, recipient resolution, single notification |
| C12 | Report totals equal stored record sums; reports never recompute money |
| C13 | The complete matrix, plus every finding from artifact 19 either mitigated or explicitly accepted |

---

## 5. Coverage expectations and what "done" means

`[RECOMMENDATION]`

1. **Every state transition** in artifact 15 has a test for the legal path and the illegal path.
2. **Every Creative route** has an authentication test, a permission test, an ownership test, and a contract test.
3. **Every money field** has a server-derivation test and a representation test.
4. **Every contested transition** has a real-database concurrency test using the two proven techniques, not a mocked race.
5. **Every private file path** has an IDOR test.
6. **Every new boundary** (domain → application → infrastructure) has a unit test that runs with no database and no container where that is possible.
7. No test asserts a business rule that is still `[OPEN DECISION]`. If a test encodes a guessed revision limit, commission percentage, or dispute window, **the test is the defect.**
8. The full suite result is reported exactly, with the 2 pre-existing failures and 1 warning labelled pre-existing.

---

## 6. Tests that must NOT be written

- Tests that assert an `[OPEN DECISION]` value.
- Tests that lock in a Creative behaviour by accident and thereby become an undocumented contract (every asserted value must be traceable to an approved decision).
- Tests that duplicate an existing golden suite's coverage of advertising behaviour.
- Snapshot/characterisation tests written to accommodate a Creative-induced change in advertising behaviour. If an advertising test needs adjusting because of Creative work, that is a **stop condition**, not a test to update (`AGENTS.md` §3, Rule 5).
- Performance rewrites. Performance findings are recorded; broad optimisation is out of scope (`AGENTS.md` §18).
