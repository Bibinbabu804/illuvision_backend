# 20 — Creative Marketplace Target Module Structure

**Generated:** 2026-09-26
**Classification:** `[RECOMMENDATION]` throughout, except where a line is marked `[CURRENT FACT]`.
**Status:** DESIGN PROPOSAL. **No file or directory below exists.** Nothing was created. This document defines where Creative code *would* live once a separately authorised implementation task begins.

---

## 1. Precondition: what exists today

`[CURRENT FACT]` Verified 2026-09-26:

```text
app/Domain/Creative/            DOES NOT EXIST
app/Services/Creative/          DOES NOT EXIST
app/Repositories/Creative/      DOES NOT EXIST
app/Http/Controllers/Creative/  DOES NOT EXIST
app/Http/Requests/Creative/     DOES NOT EXIST
app/Http/Resources/Creative/    DOES NOT EXIST
app/Policies/                   DOES NOT EXIST (0 policies in the entire application)
```

`find app -type d -empty` returns nothing: this repository contains **no empty capability directories at all**. Several Phase 0.18/0.20 audit artifacts assert that Creative directories were "CREATED (empty)". **That claim is false** and is recorded as a documentation discrepancy in `AGENTS.md` §8.

`[CURRENT FACT]` Creative totals today: **0 classes, 0 routes, 0 tables, 0 migrations, 0 seeders, 0 tests.**

Consequence: Creative is **100% greenfield**. Creating the module root below is itself a Creative task and must not happen as a side effect of an unrelated task.

---

## 2. The governing constraint on this structure

`[ARCHITECTURE DOCUMENT]` `docs/architecture/ARCHITECTURE_RULES.md` Rule 11:

```text
HTTP -> Application -> Domain -> Contracts -> Infrastructure -> Persistence/External
```

`[ARCHITECTURE DOCUMENT]` Root `AGENTS.md` §4 fixes the target tree for Project 01 and §5 forbids relocating a mis-modelled class instead of fixing its dependency. Root policy wins over any plan text.

`[ARCHITECTURE DOCUMENT]` Rule 6: repositories are introduced **only** where persistence isolation, complex query encapsulation, or testability is demonstrably justified — "avoid blanket repository boilerplate".

The structure below is therefore deliberately **not** symmetric. Every layer is included only because a Creative requirement forces it, and each entry states the forcing requirement.

---

## 3. Domain layer — `app/Domain/Creative/`

```text
app/Domain/Creative/
├── Creator/            Aggregates and rules for creator identity, status and approval
├── Service/            CreativeService aggregate: the catalogue listing a creator sells
├── Brief/              Brief aggregate: a client's statement of work
├── Proposal/           Proposal aggregate: a creator's offer, its quote derivation, its lifecycle
├── Project/            Project aggregate: the engagement created on proposal acceptance
├── Revision/           Revision round rules and limits
├── Delivery/           Delivery submission and acceptance rules
├── Review/             Client review rules and their immutability
├── Earnings/           Creator earning, accrual, dispute and payout rules
├── Shared/             Creative-only value objects shared by the aggregates above
└── Contracts/          Creative-owned contracts, if any prove necessary
```

### Per-directory content rule

`[RECOMMENDATION]` Each of the nine aggregate directories contains **only**:

| Element | Allowed in Domain? | Note |
|---|---|---|
| Aggregate root class (Eloquent model) | Yes | The one place a Creative model may be an Active Record model |
| Entity / value object classes | Yes | Pure, no framework dependency beyond support types |
| Pure domain service | Yes | Deterministic, no I/O (Rule 2) |
| Enum / state vocabulary | Yes | Statuses are code, not free strings |
| Transition guard | Yes | Plain method asserting `canTransitionTo(...)` |
| `App\Models\*` import | **No** | Prohibited by `AGENTS.md` §6. The aggregate root *is* the model; it must not import other models |
| `App\Http\*` import | **No** | Rule 11 |
| `App\Services\*` import | **No** | Rule 11. The existing 7 pinned `Domain -> Services` imports in `CampaignPricingEngine` are recorded debt, not a licence |
| Razorpay SDK, `Http::`, `Mail::`, `Storage::` | **No** | Rules 1, 2, 3 |

`[RECOMMENDATION]` Naming: a Creative model for the aggregate `Creator` should be `App\Domain\Creative\Creator\Creator`, not `App\Models\Creator`. This is deliberate — it makes the boundary visible in the class name and makes an illegal `App\Models` import of Creative data obvious in review. Compare the existing precedent: `App\Domain\Booking\Booking` and `App\Domain\Payment\...` already live in the domain.

### `Shared/` — what may legitimately be shared inside Creative

`[RECOMMENDATION]` Only value objects that are meaningless outside Creative:

```text
Money                    -> NO. See section 8. Financial primitives are shared infrastructure, not Creative.
PublicId                 -> only if the identifier strategy is decided; otherwise a value object per aggregate
BriefStatus, ProposalStatus, ProjectStatus, RevisionStatus,
DeliveryStatus, ReviewStatus, EarningStatus, PayoutStatus
ProposalQuote            -> the frozen server-derived commercial terms
AgreedTerms              -> the AGREED TERMS invariant: total == sum(lines) + tax + fee
FileVisibility           -> PUBLIC | PRIVATE
```

`[OPEN DECISION]` `PublicId` cannot be finalised until OD-DB-01 (ULID vs UUID vs prefixed sequential reference) is decided. The value object shape is the same either way; only the generation and column type change.

---

## 4. Application layer — `app/Services/Creative/`

`[CURRENT FACT]` The existing application/use-case layer is `app/Services/` (48 classes: 28 flat at the root, 20 in capability subdirectories `Campaign/`, `Booking/`, `Pricing/`, `Payment/`, `Settlement/`). The capability-subdirectory convention is already established there.

`[RECOMMENDATION]` Creative follows that existing convention rather than inventing a new layer:

```text
app/Services/Creative/
├── AcceptProposalHandler.php          use case: proposal -> project + frozen terms
├── FundProjectHandler.php             use case: funding intent / confirmation
├── SubmitDeliveryHandler.php          use case: delivery submission (+ file records)
├── AcceptDeliveryHandler.php          use case: client acceptance
├── RequestRevisionHandler.php         use case: client requests a revision round
├── SubmitRevisionHandler.php          use case: creator submits a round
├── SubmitReviewHandler.php            use case: client review, immutable
├── AccrueCreatorEarningHandler.php    use case: earning accrual (money fact)
├── ApproveCreatorEarningHandler.php   use case: approval
├── ProcessCreatorPayoutHandler.php    use case: payout batch
├── CreateBriefHandler.php             use case: brief authoring
├── SubmitProposalHandler.php          use case: proposal + server-derived quote
├── RegisterCreatorHandler.php         use case: creator registration
├── PublishCreatorServiceHandler.php   use case: catalogue publication
├── CreativeQuoteCalculator.php        thin orchestrator over the pure domain calculator
└── CreativeFileGrantor.php            use case: file access decision (private file -> authorized actor)
```

Rules for this layer:

- `[ARCHITECTURE DOCUMENT]` Rule 4: every handler that writes money or changes a lifecycle state opens `DB::transaction(...)` explicitly. No implicit transactions.
- `[ARCHITECTURE DOCUMENT]` Rule 4: **no network call inside a row lock.** The gateway call for a funding confirmation happens outside the lock, or in a queued job.
- `[ARCHITECTURE DOCUMENT]` Rule 7: a handler wraps and delegates to existing capability; it never re-implements it. `PaymentGatewayInterface`, `ExchangeRateService`, `NotificationService` and `activity_logs` are called, not copied.
- `[RECOMMENDATION]` A handler is named for the *use case*, not for the table. `AcceptProposalHandler`, never `ProposalService::accept()`.
- `[RECOMMENDATION]` Handlers emit domain events **after commit**, never inside the transaction body. See artifact 18.

`[RECOMMENDATION]` Do **not** create `app/Application/Creative/`. `[CURRENT FACT]` `app/Application/` exists but holds 32 classes that are being retired as part of the current migration. Adding to a layer under retirement would create immediate debt.

---

## 5. Repository layer — `app/Repositories/Creative/`

`[CURRENT FACT]` `app/Repositories/` currently contains only `Asset/` and `Booking/` (4 classes), plus 4 domain-owned contracts that have Eloquent implementations. `[ARCHITECTURE DOCUMENT]` Rule 6 forbids blanket repository boilerplate.

`[RECOMMENDATION]` Create a repository **only** where one of these forcing requirements is present, and record the forcing requirement in the class docblock:

| Candidate repository | Justifying requirement | Verdict |
|---|---|---|
| `CreativeProposalRepository` | Complex query: "open proposals for a brief, excluding declined/withdrawn, ordered, paginated" is reused by the brief list, the creator dashboard and the admin queue | **Justified** |
| `CreatorEarningRepository` | Concurrency: aggregate sums and the "unpaid balance" read must not be recomputed inconsistently across approval and payout handlers | **Justified** |
| `CreativeProjectRepository` | Lock-scoped read inside `DB::transaction` + `lockForUpdate` during acceptance | **Justified** |
| `CreativeFileRepository` | Visibility-filtered reads used by every file access path (this is a security control, not a convenience) | **Justified** |
| `CreatorRepository`, `CreativeBriefRepository`, `CreativeServiceRepository`, `CreativeDeliveryRepository`, `CreativeRevisionRepository`, `CreativeReviewRepository` | Plain Active Record reads and writes | **Not justified** — use the model directly |

`[RECOMMENDATION]` Repository contracts live with their domain owner where that owner already does this (the existing pattern: contract in `Domain/.../Contracts`, implementation in `Infrastructure/Persistence`). So:

```text
app/Domain/Creative/Proposal/Contracts/ProposalRepositoryInterface.php
app/Domain/Creative/Earnings/Contracts/CreatorEarningRepositoryInterface.php
app/Domain/Creative/Project/Contracts/CreativeProjectRepositoryInterface.php
app/Infrastructure/Persistence/Creative/EloquentCreativeProposalRepository.php
app/Infrastructure/Persistence/Creative/EloquentCreatorEarningRepository.php
app/Infrastructure/Persistence/Creative/EloquentCreativeProjectRepository.php
app/Infrastructure/Persistence/Creative/EloquentCreativeFileRepository.php
```

`[ARCHITECTURE DOCUMENT]` Rule 11 forbids `Domain -> Infrastructure`. The interface is in the domain; only the container binding knows the implementation.

---

## 6. HTTP layer

```text
app/Http/Controllers/Creative/
├── CreatorProfileController.php
├── CreatorServiceCatalogueController.php
├── BriefController.php
├── ProposalController.php
├── CreativeProjectController.php
├── RevisionController.php
├── DeliveryController.php
├── CreativeReviewController.php
├── CreatorEarningController.php
├── CreativeFileController.php            private file access — authorization lives here
└── CreativeDashboardController.php

app/Http/Requests/Creative/
├── StoreBriefRequest.php
├── StoreProposalRequest.php
├── SubmitRevisionRequest.php
├── SubmitDeliveryRequest.php
├── SubmitCreativeReviewRequest.php
├── StoreCreatorServiceRequest.php
└── UploadCreativeFileRequest.php

app/Http/Resources/Creative/
├── CreatorResource.php
├── CreatorServiceResource.php
├── BriefResource.php
├── ProposalResource.php
├── CreativeProjectResource.php
├── RevisionResource.php
├── DeliveryResource.php
├── CreativeReviewResource.php
├── CreatorEarningResource.php
└── CreativeFileResource.php
```

`[CURRENT FACT]` The current application has only **5** FormRequest classes and **7** API Resource classes, and most validation is inline in controllers. Creative deviating from that is deliberate and must be recorded as a deviation, not smuggled in.

`[RECOMMENDATION]` Rationale for the deviation:

1. Creative aggregates have nested, state-dependent validation (a proposal is editable only in one state; a delivery must have files; a revision round must be open). Inline validation in a controller cannot express that cleanly and tends to produce partial writes.
2. Financial fields must never come from the client. A FormRequest with an explicit allow-list is the cheapest structural defence against mass assignment of `amount`, `commission`, `status` and `earnings`.
3. Resources keep amount/status/visibility shaping in one place instead of duplicating it in the controller and in the notification payload.

`[ARCHITECTURE DOCUMENT]` Rule 1: controllers must not touch SDKs. `CreativeFileController` calls the application handler, never `Storage::` directly.

`[ARCHITECTURE DOCUMENT]` Rule 5 and `AGENTS.md` §13: adding **new** Creative routes is permitted (no existing contract changes), but no existing route, response envelope, status code or validation rule may change. Route parity must remain 385 plus the newly added Creative routes, and `tests/Feature/Golden/GoldenApiContractRegressionTest.php` must stay green.

---

## 7. Policies — `app/Policies/Creative/`

`[CURRENT FACT]` `app/Policies/` does not exist. The entire application has **0 policies** and **0 gates**. Authorization is middleware-level plus ad-hoc ownership checks inside controllers.

`[RECOMMENDATION]` Creative introduces policies as a *new* capability, not as a refactor of existing authorization:

```text
app/Policies/Creative/CreativeProjectPolicy.php
app/Policies/Creative/ProposalPolicy.php
app/Policies/Creative/CreativeFilePolicy.php
app/Policies/Creative/CreatorEarningPolicy.php
app/Policies/Creative/CreativeReviewPolicy.php
app/Policies/Creative/BriefPolicy.php
```

`[ARCHITECTURE DOCUMENT]` Rule 12: authorization is enforced at the route boundary and that remains the authoritative check. A policy is the *resource-level* answer ("may this actor touch *this* project"), which is exactly the gap the current ad-hoc checks leave open. Creative must not move the permission algorithm into a policy: `RolePermissionChecker` remains its single owner.

`[RECOMMENDATION]` `CreativeFilePolicy` is not optional. It is the control that prevents insecure direct object reference on private files (see artifact 19).

---

## 8. Infrastructure — reuse, do not create

There is **no** `app/Infrastructure/Creative/`. `[RECOMMENDATION]` Creative must not create one, except for the four Eloquent repositories justified in section 5.

| Creative need | Reuse (verified existing) |
|---|---|
| Payment gateway | `App\Domain\Payment\Contracts\PaymentGatewayInterface` + `RazorpayPaymentGateway` |
| FX | `currency_rates` + `Campaign\ExchangeRateService` / `Campaign\CurrencyService` |
| Image processing | `ImageProcessingContractInterface` via `MediaHelper` |
| File disks | `config/filesystems.php` `local` / `public` / `s3` |
| Email | `app/Mail\*` Mailable pattern (28 classes, all `ShouldQueue`) |
| Notification dispatch | `App\Services\NotificationService` + `notification_logs` |
| Audit trail | `activity_logs` |
| Configuration | `settings` (`creative.*` group) |
| Queue | `jobs` / `failed_jobs` via the existing queue connection |

`[RECOMMENDATION]` The only genuinely new infrastructure is:
1. a **file access contract** (authorize-then-stream), because no signed-URL mechanism exists today;
2. a **Creative file record** (metadata + owner + visibility), because no central file table exists.

`[ARCHITECTURE DOCUMENT]` Rule 3: the Razorpay SDK must never appear in a Creative domain or application signature. Creative funding passes through `PaymentGatewayInterface`.

`[RECOMMENDATION]` Money: `App\Domain\Payment\Support\Money` is a **float-based conversion helper, not a money value object**. Creative amounts must be `decimal(12,2)` in the database, derived server-side, and must never be represented as a PHP float in a signature that crosses a layer boundary. Whether Creative introduces a proper `Money` value object, or the shared one is upgraded, is `[OPEN DECISION]` — it must not be decided silently by a Creative task.

---

## 9. Jobs

`[CURRENT FACT]` `app/Support/Jobs` **does not exist** and there are **0** job classes in the application. `[CURRENT FACT]` All 28 Mailables implement `ShouldQueue`, so queued work currently happens through Mailables, not through job classes.

`[RECOMMENDATION]` Creative needs at least one real job (payout batch processing, and funding reconciliation if webhooks are adopted). Creating `app/Support/Jobs/` is therefore a **decision with a consequence**, not a free choice:

- Option A: `app/Support/Jobs/Creative/` — matches the target tree in `AGENTS.md` §4 and gives Creative a home for jobs. Recommended.
- Option B: keep Creative work inside queued Mailables — reuses the existing pattern but abuses Mailables as a job system and makes retries unobservable.

`[RECOMMENDATION]` A Creative job must be **thin**: it invokes an application handler. It must not contain a business workflow (root `AGENTS.md` §6, `Support/` is for genuinely cross-cutting concerns).

---

## 10. Complete proposed tree

```text
app/
├── Domain/Creative/
│   ├── Creator/{Creator.php, CreatorStatus.php, ApprovalDecision.php}
│   ├── Service/{CreatorService.php, ServiceStatus.php, RateCard.php}
│   ├── Brief/{Brief.php, BriefStatus.php, BriefAttachmentRule.php}
│   ├── Proposal/{Proposal.php, ProposalStatus.php, ProposalQuote.php}
│   ├── Project/{Project.php, ProjectStatus.php, AgreedTerms.php, RevisionLimitPolicy.php}
│   ├── Revision/{Revision.php, RevisionStatus.php}
│   ├── Delivery/{Delivery.php, DeliveryStatus.php, AcceptanceRule.php}
│   ├── Review/{CreativeReview.php, ReviewStatus.php}
│   ├── Earnings/{CreatorEarning.php, EarningStatus.php, PayoutBatch.php, PayoutStatus.php}
│   ├── Shared/{FileVisibility.php, MoneyAmount.php(conditional)}
│   └── Contracts/  (only if a Creative-wide contract proves necessary)
├── Services/Creative/           (16 handlers, section 4)
├── Repositories/Creative/       (only where justified, section 5)
├── Http/Controllers/Creative/   (11 controllers, section 6)
├── Http/Requests/Creative/      (7 FormRequests, section 6)
├── Http/Resources/Creative/     (10 Resources, section 6)
├── Policies/Creative/           (6 policies, section 7)
└── Support/Jobs/Creative/       (decision required, section 9)
```

Plus `app/Domain/Creative/../Contracts` implementations in `app/Infrastructure/Persistence/Creative/` (4 files, section 5).

`[RECOMMENDATION]` **Not** proposed, deliberately:

| Rejected directory | Why |
|---|---|
| `app/Domain/Creative/Common/`, `Utils/`, `Managers/`, `SharedLogic/` | `AGENTS.md` §7 forbids vague umbrella directories |
| `app/Application/Creative/` | The `app/Application/` layer is being retired (32 deferred classes) |
| `app/Infrastructure/Creative/` beyond the 4 repositories | `AGENTS.md` §6: infrastructure is for adapters, not for new Creative plumbing |
| `app/Http/Controllers/Creative/Admin/` sub-split | 11 controllers do not justify a second namespace level; revisit above ~20 controllers |
| A separate `app/Models/Creative/` namespace | Would hide the domain boundary. `App\Models\Creator` is exactly the ambiguity this structure exists to prevent |

---

## 11. What this structure deliberately does not decide

`[OPEN DECISION]` Carried forward from artifacts 06, 09 and 10; none may be settled by a Creative implementation task:

1. Identity model — can one user be advertiser **and** creator (OD-DB-08)? This determines whether `Creator` is a role or an independent aggregate anchored on `users.id`. **Blocking.**
2. Public identifier strategy (OD-DB-01) — determines `PublicId` column type on every table and every route parameter.
3. Brief targeting — a specific creator service, or an open opportunity (OD-DB-02)? Determines `creative_briefs.creator_service_id` nullability and whether `creative_brief_matches` exists.
4. One proposal per brief or many (OD-DB-03) — determines unique constraints and whether acceptance auto-declines siblings.
5. Escrow / milestone funding in scope (OD-DB-04).
6. Earning accrual trigger and dispute window (OD-DB-05).
7. Commission basis, approval authority, payout cadence, payout provider (OD-03/04/05 in artifact 06).
8. Tax, third-party costs, multi-currency (OD-DB-06, OD-DB-07).
9. Whether `activity_logs` can carry a Creative subject polymorphically, or a Creative-scoped audit table is needed (OD 10-OD-05).
10. Whether Creative files are private-by-default with signed access, and the retention/consent rules (OD 10-OD-07).

---

## 12. Verification obligations for whoever implements this

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §17 mandates, for any architecture or code-movement work:

```text
tests/Unit/Architecture/ArchitectureRuleTest.php
php artisan route:list          route parity
composer dump-autoload          autoload + container resolution
php artisan test                full suite
./vendor/bin/pint --test        style, for the files the task actually touches
```

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §17 records the pre-existing reference state that must not be silently "improved": **673 passed, 2 failed, 1 warning, 4 skipped, 5564 assertions**, with the 2 failures being message-wording drift in `tests/Feature/BookingConcurrencyTest.php` and `tests/Feature/CampaignBookingPricingIntegrityTest.php`, and the warning in `MediaHelperTest`. `./vendor/bin/pint --test` fails repository-wide for pre-existing reasons.

`[RECOMMENDATION]` A Creative task that changes any of those outcomes has introduced a regression and must report it, not absorb it.
