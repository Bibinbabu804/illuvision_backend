# 27 — Creative Marketplace Backend Audit: Master Report

**Generated:** 2026-09-26
**Reader:** senior engineer / project architect first. This is the input document for a separate "Creative Marketplace Backend Developer Guide" for a junior developer.
**Repository:** `illuvision/core-backend` — Laravel 11, PHP ^8.2, modular monolith, Project 01 (Advertising Marketplace).
**Status:** **READ-ONLY AUDIT COMPLETE.** No production code, migration, schema, route, API, financial rule, authorization rule, class or test was modified. Nothing Creative was implemented. 26 companion artifacts accompany this report in `docs/audit/creative-marketplace/`.

Every substantive statement is classified `[CURRENT FACT]`, `[ARCHITECTURE DOCUMENT]`, `[INFERENCE]`, `[RECOMMENDATION]` or `[OPEN DECISION]`. Recommendations are never presented as existing functionality.

---

## 1. Executive summary

`[CURRENT FACT]` **Creative Marketplace does not exist in this repository.** Not partially, not as a stub: **0 classes, 0 routes, 0 tables, 0 migrations, 0 seeders, 0 tests.** The directories `app/Domain/Creative`, `app/Services/Creative`, `app/Repositories/Creative`, `app/Http/Controllers/Creative`, `app/Http/Requests/Creative` and `app/Http/Resources/Creative` **do not exist**, and `find app -type d -empty` returns nothing — this repository contains no empty capability directories at all.

`[CURRENT FACT]` Several Phase 0.18/0.20 audit artifacts assert those directories were "CREATED (empty)". **That claim is false** and is recorded as a documentation discrepancy in root `AGENTS.md` §8. Anyone who trusts those documents will believe a module root already exists and will build on a fiction.

`[RECOMMENDATION]` The audit's central conclusion is a ratio, and it is deliberately unflattering to the idea that Creative is a configuration exercise: of the **40 Creative requirements** analysed in artifact 26, **18** are genuinely new implementation, **13** are consumed through an existing contract or store rather than an existing aggregate, **4** are direct reuse, **2** extend an existing component, **2** are do-not-reuse, and **1** is blocked on an open decision. A further **13 components** are named in that artifact's `do_not_reuse_register` as traps reachable by name similarity alone. **No requirement needs a wrapper or an extraction refactor** - nothing in the current system is both Creative-relevant and awkwardly packaged. Creative reuses identity, an authorization vocabulary, a payment gateway **contract**, storage disks, an image-processing contract, FX, notifications, an audit table and a configuration store — and essentially nothing else of substance.

`[RECOMMENDATION]` Creative is **blocked on exactly one decision**: whether one user may be both an advertiser and a creator (`OD-DB-08` / `OD-AUTH-01`). That question is not a schema detail. `RolePermissionChecker::permissionsForRole()` (`app/Support/Authorization/RolePermissionChecker.php:50-69`) resolves a **single slug** from the free-text `users.role` column, so one user holds exactly one role. Setting `role='creator'` to grant `creative.*` permissions **removes every other permission that user had**. A `creator` role row is therefore not merely insufficient for a dual-role user — it is actively destructive. Phase C1 must not start until this is answered.

`[RECOMMENDATION]` The three other findings that most shape the design:

1. **Money is reusable as a contract, never as a record.** `PaymentGatewayInterface` is domain-owned, SDK-free and booking-agnostic — reuse it verbatim. The `payments` **table** is advertising-bound (`booking_id NOT NULL ON DELETE CASCADE`, and confirmation transitions a booking status), so Creative funding is a new table. This single distinction prevents both a parallel payment stack and an edit to an existing financial table.
2. **Private file access is entirely new work.** There are zero signed URLs, no visibility column, no central file record and no access policy in the platform. The path of least resistance today is the **public** disk. A Creative implementation that puts client or creator files there inherits a data leak.
3. **No duplicate-request protection exists anywhere in the platform.** No idempotency keys, no optimistic locking, and only **3 real `lockForUpdate` call sites in the whole of `app/`** (in 24 files that contain a real `DB::transaction(` call at 41 sites). Creative money and state transitions must supply their own protection, by database constraint first.

---

## 2. Current backend state

`[CURRENT FACT]` Measured 2026-09-26 by direct source inspection.

| Measure | Value | Measure | Value |
|---|---|---|---|
| Routes | **385** (380 `api`-prefixed, 5 non-api) | Migrations | **112** |
| Controllers | **68** (48 flat + `Api/` 17 + `Auth/` 3) | Distinct tables | **73** |
| Models | **62** | `Schema::create` calls | 75 (two tables created twice) |
| Services | **48** (28 flat + 20 in capability subdirs) | Foreign-key definitions | **96** (94 `constrained()` + 2 legacy `foreign()`) |
| FormRequests | **5** | API Resources | **7** |
| Middleware classes | **1** | Policies / Gates / Listeners / Jobs | **0 / 0 / 0 / 0** |
| Mailables | **28** (all `ShouldQueue`) | Domain events | **33** + 1 subscriber |
| Test files | **85** | Application (retiring) classes | **32** |

`[CURRENT FACT]` Route definition files contain 347 explicit `Route::` calls in `routes/api.php` and 2 in `routes/web.php`; the remainder of the 385 come from `resource()` expansions. Definition calls and route entries are different numbers and are not summable.

`[CURRENT FACT]` Full suite, re-run during this audit: **673 passed, 2 failed, 1 warning, 4 skipped, 5564 assertions**. Both failures are pre-existing message-wording drift (`tests/Feature/BookingConcurrencyTest.php:371`, `tests/Feature/CampaignBookingPricingTest.php`→`tests/Feature/CampaignBookingPricingIntegrityTest.php:228`); the warning is `MediaHelperTest`. `tests/Unit/Architecture/ArchitectureRuleTest.php`: **14 passed**. `./vendor/bin/pint --test` fails repository-wide for pre-existing reasons.

`[CURRENT FACT]` Services: 10 803 LOC total; **41 of 48 import `App\Models`**; 14 contain a real `DB::transaction(` call; exactly **1** contains a real `->lockForUpdate(` call (`InventoryService.php:48`). Largest: `BudgetRecommendationService` (1445 LOC), `ManualBookingService` (647), `BookingCapacityService` (602), `AssetImportService` (565), `PricingRuleEngine` (506).

---

## 3. Current architecture

`[ARCHITECTURE DOCUMENT]` The approved target is a modular monolith: `HTTP → Application/Services → Domain → Contracts → Infrastructure → Persistence`. `ARCHITECTURE_RULES.md` has 13 rules; Rule 11 (dependency direction) and Rule 12 (authorization at the route boundary with a single algorithm owner) are the two that most constrain Creative.

`[CURRENT FACT]` The repository is mid-migration and the recorded debt is deliberate, pinned and ordered:

- `App\Domain\Pricing\CampaignPricingEngine` imports 7 legacy `App\Services\*` classes — **enforced** by `ArchitectureRuleTest`, which fails both on an eighth import and on a silent removal.
- 6 Domain files import `App\Models` (9 edges) — tracked, **not yet enforced**.
- 11 service-locator call sites; 33 repository-extraction candidates; 27→28 flat root services.
- 2 failing tests + 1 warning.

`[RECOMMENDATION]` **None of this is Creative's to fix** (`AGENTS.md` §18, §19). Resolving it out of order creates unreviewed behavioural risk in exactly the areas the phase plan protects. The only Creative-specific action is to add new enforcement in Phase C1 so Creative does not *add* to the debt.

---

## 4. Target architecture (Creative)

`[RECOMMENDATION]` Nine aggregates inside Project 01, each with exactly one domain owner (artifact 06): `Creator`, `CreatorService`, `Brief`, `Proposal`, `Project`, `Revision`, `Delivery`, `Review`, `Earnings`; 4 domain services; 13 domain events; 4 justified repository contracts; 16 application handlers; 11 controllers, 7 FormRequests, 10 Resources, 6 policies. Full tree and the reasoning for every entry: artifact 20.

The dependency direction for every Creative class:

```text
app/Http/Controllers/Creative        (thin: validate -> authorise -> call a handler -> Resource)
        |
app/Services/Creative/               (use-case orchestration, explicit DB::transaction, events after commit)
        |
app/Domain/Creative/                 (pure aggregates, value objects, enums, transition guards, no I/O)
        |
contracts  ->  app/Infrastructure/Persistence/Creative  (4 Eloquent repositories only)
```

`[RECOMMENDATION]` What Creative deliberately does **not** get: a `Common/`/`Utils/`/`Managers/` umbrella directory (`AGENTS.md` §7); an `app/Application/Creative` layer (that layer is being retired); an `app/Infrastructure/Creative` beyond the four repositories; a state-machine framework; a search engine; a second queue, cache, storage, auth, payment, notification or authorization subsystem.

---

## 5. Current vs target gap

| Dimension | Current | Creative target | Gap size |
|---|---|---|---|
| Classes / routes / tables / tests | 0 / 0 / 0 / 0 | ~60 classes, ~40 routes, 16–19 tables, ~25 test files | **Everything** |
| Identity | One `users` table, free-text single role | Reused unchanged, anchored by `creators.user_id` | None — reuse |
| Authorization vocabulary | 5 seeded roles, ~70 permissions, 0 policies | +1 role row (blocked by OD-DB-08), +`creative.*` grants, +6 **new** policies | Small, one blocked decision |
| Money records | `payments` bound to bookings | 6 new Creative tables over the same gateway contract | New, deliberate |
| File access | Public disk, no visibility, no signed URL, unvalidated upload | File record with owner + visibility, policy, authorize-then-stream | **New and unavoidable** |
| Notifications | 1 subscriber, 0 listeners, `creator` absent from recipients | +13 events, +Creative Mailables, +`creator` recipient type | Small extension |
| Concurrency safety | 3 lock sites, no idempotency, no optimistic locking | Per-aggregate design with unique-constraint-first idempotency | **New and mandatory** |
| Reporting | Flat advertising analytics services | New Creative queries reading canonical records | New |
| Tests | 85 files, 10 golden, 5 security, 1 architecture | +Creative boundary, domain, application, API, authorization, concurrency, financial, file, notification, state, idempotency suites | New |

---

## 6. Creative Marketplace scope

`[INFERENCE]` Derived from the business lifecycle in artifact 07 (18 steps) and the aggregate set in artifact 06. A client publishes a brief; a creator responds with a priced proposal; acceptance creates a project with frozen terms; the project is funded; work is delivered, requested for revision, delivered again, and accepted; the client reviews; a creator earning accrues and is paid out. `[OPEN DECISION]` Eleven commercial rules in that sentence are undecided (see §27).

---

## 7. Existing Creative functionality

`[CURRENT FACT]` **None.** An exhaustive keyword sweep of `app/`, `routes/`, `database/`, `tests/`, `config/` and `bootstrap/` for creator/creative/brief/proposal/portfolio/revision/delivery/review/earnings/commission/payout/scope/estimate terms found **16 concepts examined, 0 fully implemented as Creative capability**, and **4 partial matches that are semantically different** (artifact 04). The three that matter most:

| Name in this repository | What it actually is | Why it is not the Creative thing |
|---|---|---|
| "Asset" | Advertising inventory — `screens`, `screen_categories`, coverage tables | A purchasable ad slot, not a creative work file. The most likely wrong target for a new developer. |
| "Review" (`booking_media.review_status`) | **Admin moderation** of client-uploaded advertisement files | A moderation queue, not a client review of creator work. |
| "Rating" (`testimonials.rating`) | **Public marketing** content with no author binding and no soft delete | A commercial Creative review written here would be publicly editable, unowned and un-auditable. |

---

## 8. Shared functionality available for reuse

`[CURRENT FACT]` 24 shared capabilities were assessed (artifact 05). The ones Creative genuinely uses:

| Capability | Existing component | Creative use |
|---|---|---|
| Identity | `users`, `personal_access_tokens`, `password_reset_tokens` | Direct — no second auth system |
| Invitation | `password_setup_tokens` (`ScreenProviderService::inviteToPortal` precedent) | Direct if invite-first onboarding |
| Role/permission vocabulary | `roles`, `role_permissions`, `RolePermissionChecker` | Extend with rows (blocked by OD-DB-08) |
| Payment gateway | `PaymentGatewayInterface` + `RazorpayPaymentGateway` | Direct, through the contract |
| Money conversion | `App\Domain\Payment\Support\Money` (float-based **conversion helper**, not a value object) | At the gateway boundary only |
| FX | `currency_rates`, `ExchangeRateService`, `CurrencyService`, `currency:sync-rates` | Direct if multi-currency (OD-DB-07) |
| Storage disks | `config/filesystems.php` (`local`, `public`, `s3`) | Direct |
| Image processing | `ImageProcessingContractInterface` via `MediaHelper` | Direct |
| Notifications | `NotificationService`, `NotificationEventSubscriber`, 28 Mailables, `notification_logs` | Extend with a `creator` recipient |
| Audit | `activity_logs` | Direct, if it can express a Creative subject (OD-AUDIT) |
| Configuration | `settings` | Extend with a `creative.*` group — non-financial keys only |
| Geography | `countries`, `states`, `cities` | Direct, conditional on address capture |
| Queue / cache | `jobs`, `failed_jobs`, `cache`, `cache_locks` | Direct |

---

## 9. Reuse / Extend / New Development Matrix

`[RECOMMENDATION]` Full matrix: artifact 26 (40 requirement rows with evidence, dependencies and risk). Aggregate:

| Reuse type | Rows | Examples |
|---|---|---|
| `DIRECT_REUSE` | 4 | Identity, invitation tokens, shared geography, platform cache/queue |
| `REUSE_THROUGH_CONTRACT` | 13 | Payment gateway contract, storage disks + image-processing contract, `activity_logs`, `notification_logs`, `currency_rates` + FX, the lock/transaction discipline, public identifier strategy |
| `EXTEND` | 2 | `roles` + `role_permissions` rows, the `settings` creative group |
| `WRAP` | 0 | No requirement needs a facade - recorded as a deliberate finding, not an omission |
| `EXTRACT` | 0 | No shared capability needs a dedicated extraction refactor for Creative |
| `NEW_IMPLEMENTATION` | 18 | Creator, catalogue, brief, proposal + server-derived quote, project + funding, delivery, revision, review, earnings, payout, file record, file access grantor, 6 policies, 13 events, Creative reporting, jobs |
| `DO_NOT_REUSE` | 2 (+13 in the register) | Rows RM-14 (`testimonials`) and RM-37 (`bookings`); the register additionally names `payments`, `screens`, `partners`, `reward_*`, `screen_providers*`, the pricing tables, `products`, marketing tables, `SettlementService`/`CommissionService`, `FileController`'s upload path and `app/Application/` |

`[RECOMMENDATION]` The five highest-consequence traps, each named so it cannot be walked into:

1. **`bookings`** — writing a Creative project there would change advertising pricing, availability, settlement, revenue splits and 20+ routes. `creative_projects` has **no FK to bookings**, by design.
2. **`payments`** — relaxing `booking_id` would alter an existing financial table and every booking payment query. Reuse the contract, never the record.
3. **`testimonials`** — a commercial review on a public marketing surface.
4. **`booking_media`** — its file columns are a fine template; its *absence* of owner, visibility and access check is a fine thing to not copy.
5. **The 10.00/90.00 revenue split** on `screens`/`screen_providers` — provider commercial terms that a Creative commission must not inherit.

---

## 10. Creative domain boundary

`[RECOMMENDATION]` Artifact 06. Nine aggregates, one owner each. `[INFERENCE]` The boundary test that actually matters is not "does Creative import from advertising" (it will not, by construction) but "**does advertising import from Creative, or does a Creative row reference a booking**". The first is a dependency inversion; the second is the coupling that turns a bounded context into a shared table.

`[OPEN DECISION]` One term collision remains unresolved and needs an architecture ruling: `AGENTS.md` §7 lists `Creative` as a Project 01 domain, and `AGENTS.md` §1 refers to "Project 02 — Creative Marketplace" as a *separate* project with a *separate* database — while §1 also places Creative as a bounded context inside Project 01. Creative cannot have both a separate database and a context inside Project 01. The target structure uses the inside-Project-01 reading.

---

## 11. Creative application layer

`[RECOMMENDATION]` 16 handlers in `app/Services/Creative/`, named for use cases not tables: `RegisterCreatorHandler`, `PublishCreatorServiceHandler`, `CreateBriefHandler`, `SubmitProposalHandler`, `AcceptProposalHandler`, `FundProjectHandler`, `SubmitDeliveryHandler`, `AcceptDeliveryHandler`, `RequestRevisionHandler`, `SubmitRevisionHandler`, `SubmitReviewHandler`, `AccrueCreatorEarningHandler`, `ApproveCreatorEarningHandler`, `ProcessCreatorPayoutHandler`, `CreativeFileGrantor`, `CreativeQuoteCalculator` (thin orchestrator over a pure domain calculator). Each money- or lifecycle-affecting handler opens `DB::transaction` explicitly and dispatches events **after commit**.

---

## 12. Creative infrastructure dependencies

`[RECOMMENDATION]` Creative needs **no new infrastructure layer**. Exactly four new files under `app/Infrastructure/Persistence/Creative/` (the justified repositories), plus two genuinely new capabilities that are not infrastructure but access control: the Creative file record and the file grantor. `app/Support/Jobs/Creative/` is a **decision** (the parent directory does not exist) and is recommended over abusing queued Mailables as a job system.

---

## 13. Database current state

`[CURRENT FACT]` 112 migrations, **73** distinct tables, **96** foreign-key definitions, 0 views, 62 models, 11 tables with no model, 1 orphan table (`screen_types`). 16 file-bearing columns scattered across 14 tables with **no central file record**. Money is `decimal(12,2)`; percentages `decimal(5,2)`; statuses are a mix of DB ENUMs, PHP enums and VARCHARs. `[CURRENT FACT]` Four FK-like columns are **not constrained**: `bookings.screen_category_id`, `booking_screens.screen_category_id`, `booking_screens.screen_provider_id`, `asset_coverage_cities.state_id`.

---

## 14. Proposed Creative database design

`[RECOMMENDATION]` Artifact 09: **14 unconditional new tables + 2 junction + 3 conditional**, 10 candidates rejected after consideration, 3–5 existing tables extended by rows only. Implementation order is specified and includes one **deferred FK** (`creative_proposals.project_id` is added after `creative_projects` exists). `creative_projects` carries the `AGREED TERMS` invariant; `creator_earnings` and the funding rows are immutable once written. No migration was written.

---

## 15. API architecture

`[CURRENT FACT]` No version prefix on the large majority of routes; `/api/v2/*` exists on 19 routes and **deliberately uses a different response envelope**. 5 distinct response envelopes in production, including one emitted by a domain exception. Validation is mostly inline (5 FormRequests for 385 routes). Response shaping is mostly hand-built (7 Resources). Routes are overwhelmingly keyed by integer id. 384 of 385 routes are unthrottled. `per_page` and `perPage` are both in use. 16 routes have `permission:*` but no `auth:sanctum` (all `/api/v2/*`), which is why the middleware emits its own 401.

`[RECOMMENDATION]` Artifact 14 recommends an **unversioned** `/api/creative/*` group matching existing convention, with FormRequests and Resources used consistently (a deliberate, recorded deviation), integer ids internal and a **public identifier** external — the strategy itself being `[OPEN DECISION]` OD-DB-01.

---

## 16. Authorization

`[CURRENT FACT]` Single `web` session guard plus Sanctum; tokens never expire (`config/sanctum.php` expiration is `null`). `users.role` is free text with no FK. 5 seeded roles, ~70 permissions. `RolePermissionChecker` is the single algorithm owner; the super-admin bypass exists **only** in `PermissionMiddleware` and is deliberately absent from the checker — an asymmetry Rule 12 requires be preserved. 0 policies, 0 gates; ownership checks are ad hoc inside controllers.

`[CURRENT FACT]` Three verified authorization findings that materially affect Creative:

1. Code writes and queries role values that are **not seeded** — `partner` (`PartnerService.php:29`), `user` (`ManualBookingService.php:93`, `GoogleAuthController.php:64`), `client` (`AdminRewardsController.php:31`) — so the checker resolves them to an **empty** permission set, silently.
2. `settlements.view` / `.approve` / `.pay` are used on **12 live routes and granted to no role** (`grep "settlements\." database/` returns nothing). Those routes are super-admin-only **by accident**, and separation of duties for any Creative payout approval is currently unenforceable.
3. `routes/api.php:717` applies an **unregistered `admin` middleware alias**; `app("admin")` throws `BindingResolutionException`. Two routes are unreachable and untested.

`[RECOMMENDATION]` Creative adds 6 resource-level policies as a **new** capability, without migrating any of the 385 existing routes, and adds a `creator` role plus `creative.*` grants — the latter **blocked by OD-DB-08** (see §1).

---

## 17. Payment & financial integration

See artifact 12 in full. `[RECOMMENDATION]` Reuse `PaymentGatewayInterface`; own new funding, refund, earning and payout records; copy three disciplines and nothing else (auditable split snapshot, append-only financial log, locked payout transition). Refuse both a parallel payment stack and being forced into booking-specific financial logic.

`[CURRENT FACT]` **No payment webhook exists.** Verification is client-initiated at `POST /api/payments/razorpay/verify`. `[OPEN DECISION]` Whether Creative adopts a webhook, with signature validation, replay rejection and idempotent handling, is undecided.

---

## 18. File & storage architecture

`[CURRENT FACT]` `LaravelStorageAdapter` hard-codes `DISK = 'public'`; the `local` (private) disk exists and **is never used**; there are zero signed URLs; `FileController::upload`/`videoupload` have **no FormRequest validation**; the adapter's `url()` resolves against the default disk rather than its own `DISK`. 6 controllers call `Storage::` directly, bypassing the contract.

`[RECOMMENDATION]` Creative reuses the disks and the image-processing contract, and adds a `creative_files` record carrying **owner + visibility**, a `CreativeFilePolicy`, and an authorize-then-stream grantor. `[OPEN DECISION]` Twelve storage questions are open (OD-ST-01…12), including whether files are private by default, retention, erasure versus immutability of delivered work, and signed-URL lifetimes.

---

## 19. Events & notifications

`[CURRENT FACT]` 33 domain events; **zero listener classes** — `NotificationEventSubscriber` is the only consumer; 28 Mailables, all `ShouldQueue`; `notification_logs` recipient roles **exclude `creator`**; events are dispatched synchronously from workflows; no durable de-duplication (request-local at best); `notificationKey()` exists on all 33 events and is called by nothing.

`[RECOMMENDATION]` 13 Creative events, each with producer, payload, consumer, sync/async, retry, idempotency requirement and after-commit timing; new Creative Mailables; `creator` added to `notification_logs.recipient_type`. Converting **existing** synchronous notification behaviour to queued behaviour is a platform change requiring its own authorisation and is forbidden in a Creative task.

---

## 20. State machines

`[RECOMMENDATION]` Nine lifecycles in artifact 15, with states, actors, authorization, side effects, database changes, events, notifications and financial effects. `[CURRENT FACT]` There is **no generic state-machine abstraction** in the codebase — statuses are DB ENUMs, PHP enums and VARCHARs with hand-written transitions. `[RECOMMENDATION]` Plain PHP enums + transition guards + policies. Do not introduce a framework or a package. `EXTRACT` was considered and rejected: there is no shared abstraction to extract, only repeated idioms.

`[CURRENT FACT]` A caution for anyone copying the nearest precedent: `BookingStatus` has 8 cases while `Booking::STATUS_*` has 13, and the `?? []` at `BookingWorkflowService.php:45` silently makes the 5 unmapped states **terminal** — untransitionable bookings. Copy the shape, never that implementation.

---

## 21. Transaction & concurrency model

`[CURRENT FACT]` 24 files contain a real `DB::transaction(` call (41 sites); **3 real `lockForUpdate` call sites in all of `app/`**; **no optimistic locking** (no version column in any of the 112 migrations); **no idempotency mechanism**; **no after-commit mechanism**; three ad-hoc duplicate guards exist (one hard-coded throw in `PaymentService`, one idempotent return in `CommissionService`, one race guard in `ExpirePendingBookings`). Verified violation of Rule 4: `RefundService.php:51` calls the gateway **inside** a transaction.

`[RECOMMENDATION]` Nine Creative-critical operations each get a defined boundary, lock, re-check, unique constraint and duplicate-request protection (artifact 16, artifact 24). The two proven testing techniques — a real second transaction blocked by a held row lock, and interleaved writes with `usleep()` — are reused rather than reinvented.

---

## 22. Security risks

`[CURRENT FACT]` Artifact 19 registers **25 prospective risks: 12 P0, 10 P1, 3 P2**, each with evidence, attack scenario, recommended mitigation and whether an open decision blocks it. **None was fixed by this audit**, per the task's safety rules.

The P0 classes: unauthorized project/revision access (IDOR); file access leakage through the public disk; unauthorized earnings disclosure; unauthorized or double-executed payout; proposal manipulation; **price manipulation via a client-supplied amount**; payment manipulation (client-initiated verification with no webhook reconciliation); review manipulation and self-review; privilege escalation through the free-text role column combined with the middleware-only super-admin bypass; mass assignment of nested creative payloads; missing policy layer; and absence of an audit trail for financial operations.

`[RECORDED, NOT FIXED]` Platform security findings found during the audit and deliberately left alone: `BookingController::deleteMedia` (lines 153-163) has **no ownership check** while `uploadMedia` (lines 116-118) does; `RolePermissionChecker` accepts two spellings of super-admin; Sanctum tokens never expire; unauthenticated `api/gallery` writes at `routes/api.php:703`; 69 routes with neither auth nor permission, several of them writes; 384 of 385 routes unthrottled.

---

## 23. Architectural constraints

`[ARCHITECTURE DOCUMENT]` The 13 rules, restated as Creative obligations: Rules 1–4 (HTTP decoupling, pure domain, vendor encapsulation, explicit financial transaction boundaries and no network calls inside locks), Rule 5 (frozen external contracts), Rule 6 (repositories only where justified), Rule 7 (zero business-logic duplication — wrap and delegate), Rule 8 (modular monolith; no microservices, no event sourcing), Rule 9 (pricing determinism), Rule 10 (legacy isolation behind adapters), Rule 11 (dependency direction; **fix a mis-modelled dependency, never relocate the file**), Rule 12 (authorization at the route boundary, one algorithm owner, preserve the super-admin asymmetry), Rule 13 (verified deletion — reference counting **and** framework-registration verification). Full Creative rule set with failure modes and checks: artifact 22.

---

## 24. Do-not-touch areas

`[RECOMMENDATION]` Artifact 23, justified item by item. Headlines: advertising booking behaviour; campaign pricing; advertising availability; existing payment verification and the `payments` table; settlement and provider commercial logic; partner/provider commercial relationships; all existing production API contracts; authentication core; shared infrastructure; all 43 tables Creative must not touch; the CMS application; and the Creative directories themselves, which do not yet exist. Plus the ~20 tables that may only receive **rows**, and the 13 items in the `do_not_reuse_register` of artifact 26.

---

## 25. Implementation sequence

`[RECOMMENDATION]` Artifact 24, derived from real data, rule and capability dependencies rather than the illustrative phase list in the brief. **C0** decisions → **C1** foundation + boundary guards → **C2** creator, files, audit → **C3** catalogue → **C4** brief → **C5** proposal + server-derived quote → **C6** project + frozen terms + funding → **C7** delivery → **C8** revision → **C9** review → **C10** earnings + payout → **C11** notifications → **C12** reporting → **C13** hardening. Two orderings were explicitly **rejected on evidence**: earnings before delivery/review (an earning needs an accrual trigger, which is undecided), and notifications before the event vocabulary is final.

`[RECOMMENDATION]` The three riskiest phases are **C6** and **C10** (money and concurrency, both high irreversibility) and **C2/C7** (file access).

---

## 26. Testing strategy

`[RECOMMENDATION]` Artifact 25. Fully reusable: `ArchitectureRuleTest`'s token-stream technique, `IntegrationBoundaryTest` (+ its immediate/queued mail doubles), all 10 golden suites, `tests/Feature/Security/` (especially `Sec03PermissionVocabularyTest`, which is exactly the guard Creative permissions need), `GoldenTestSetupTrait`'s seeding style, the existing unit-test layout, `UserFactory`, and `tests/TestCase.php`'s credential handling. New: 12 test families, from a Creative boundary test in C1 through state-transition, concurrency, financial, file-access, notification and idempotency suites.

`[RECOMMENDATION]` Two rules that matter more than the list: a test must never assert a value that is still `[OPEN DECISION]` (if a test encodes a guessed revision limit or commission rate, **the test is the defect**); and an advertising test that needs adjusting because of Creative work is a **stop condition**, not a test to update.

---

## 27. Open decisions

`[OPEN DECISION]` **13 decisions block implementation**, plus ~60 further questions are recorded per artifact. The blocking set:

| ID | Question | Blocks |
|---|---|---|
| **OD-DB-08 / OD-AUTH-01** | Can one user be both advertiser and creator? Single-slug role resolution makes a `creator` row destructive for such a user. | **C1 — everything** |
| OD-DB-01 | Public identifier strategy (ULID / UUID / prefixed reference) | C3 |
| OD-DB-02 | Is a brief bound to a creator service or an open opportunity? | C4 |
| OD-DB-03 | One proposal per brief, or many? | C5/C6 |
| OD-DB-04 | Is escrow / milestone funding in scope? | C6 |
| OD-DB-05 / OD-TC-08 | When does an earning accrue; is there a dispute window? | C9/C10 |
| OD-TC-07 | Does Creative adopt a payment webhook, and with what signature/replay/idempotency contract? | C6 |
| OD-TC-09 / OD-TC-14 | Commission on gross or net; where does the rate live; snapshot or read at payout? | C10 |
| OD-TC-12 / OD-DB-06 | Tax and third-party costs in scope? | C6 |
| OD-TC-13 / OD-DB-07 | Multi-currency or INR only? (no precedent: the existing settlement table has no currency column) | C6 |
| OD-TC-15 / OD-TC-16 | Is a payout a gateway disbursement or a recorded manual transfer? Period, cadence, threshold? | C10 |
| OD-SM-08 | Revision limit, and who owns it | C8 |
| OD-FILE / OD-ST-01,02,09,10 | Private by default? Retention, consent, signed-URL lifetimes, contract location | C2 |
| OD-AUDIT | Can `activity_logs` express a Creative subject? | C2 |
| OD-JOBS | Create `app/Support/Jobs/Creative/`? | C10 |
| OD-MONEY | Does Creative introduce a money value object, or is the shared helper upgraded? | C5/C6 |
| OD-AUTH-05 | Who holds Creative financial authority? No `finance` role exists and `settlements.*` is granted to nobody, so separation of duties is unenforceable today. | C10 |

`[RECOMMENDATION]` Handling rule for all of them: record as deferred, state the exact missing information, proceed with everything that does not depend on it, and ask. **Do not pick a default and move on** — a default silently becomes the business rule, and artifact 10 shows the specific harm already caused by naming `creative.revision_limit` for a rule that does not exist.

---

## 28. Risks

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| 1 | A Creative row lands in `bookings` or a Creative FK points at a booking | **Critical** | `creative_projects` has no FK to bookings; artifact 23 + 26 name it; architecture test in C1 |
| 2 | `payments` is relaxed or reused for Creative funding | **Critical** | Reuse the contract only; new tables; golden payment suites must stay green |
| 3 | Double-paid earning or duplicate accepted proposal under concurrency | **Critical** | Unique constraints first, then locks; real concurrency tests, not mocks |
| 4 | Private files on the public disk | **P0** | `creative_files` with owner + visibility; policy; authorize-then-stream; IDOR tests |
| 5 | A client-supplied amount becomes the price | **P0** | Server-derived quote; a request field that changes nothing is a test |
| 6 | An invented commission %, revision limit or dispute window becomes de facto policy | **High** | `[OPEN DECISION]` discipline; no settings key for an undecided rule; no test may encode a guess |
| 7 | The single-slug role model strips a dual-role user's permissions | **High** | OD-DB-08 blocks C1 |
| 8 | An advertising golden test "adjusted" to accommodate Creative | **High** | Rule 5 stop condition; the suite is the contract |
| 9 | Creative copies a legacy pattern with a known defect (gateway-in-transaction, unlocked settlement approval, public-disk default) | **Medium** | Artifact 12 names each one as a counter-example; artifact 11 marks the services do-not-reuse |
| 10 | 13 `do-not-reuse` components reached by name similarity (`testimonials`, `screens`, `booking_media`, `Asset`) | **Medium** | Named explicitly in artifacts 21, 23 and 26 |
| 11 | Duplicate infrastructure: second policy layer vocabulary, second notification path, second settings store, second FX | **Medium** | Duplicate watchlist in artifact 26, reviewed in the PR checklist |
| 12 | Scope creep into a search engine, state-machine framework or new package | **Low** | Explicitly out of scope in artifacts 20, 24, 25 |

---

## 29. Junior developer handoff requirements

The exact first 10 technical things to understand before writing Creative code:

1. **The dependency direction, and that it is enforced.** `HTTP → Services → Domain → Contracts → Infrastructure`. `tests/Unit/Architecture/ArchitectureRuleTest.php` already fails the build on a new `Domain → Services` import. Domain must not import `App\Http`, `App\Services` or `App\Models`; no `Http::`, `Mail::`, `Storage::` or vendor SDK in domain or application signatures; no service locator.
2. **The vocabulary collisions.** In this repository "Asset" means advertising inventory, "Review" means advertisement moderation, and `testimonials.rating` is public marketing content. Creative work files are `CreativeFile`. Getting this wrong sends you to the wrong table.
3. **Reuse the contract, never the record.** `PaymentGatewayInterface` is shared; the `payments` **table** is advertising-bound (`booking_id NOT NULL ON DELETE CASCADE`). Same shape with `SettlementService`, `CommissionService` and `booking_media`: the discipline is reusable, the table is not.
4. **Money is server-derived, `decimal(12,2)`, and immutable.** A client never supplies an amount. A financial row is written once; corrections are new compensating rows. `App\Domain\Payment\Support\Money` is a float-based *conversion helper for the gateway boundary*, not a money type.
5. **Authorization has one algorithm owner and a deliberate asymmetry.** `RolePermissionChecker` resolves by a single role slug; the super-admin bypass exists only in `PermissionMiddleware`. Preserve both. Route middleware is the authoritative check; a policy answers the resource-level question.
6. **Concurrency shape and the absence of platform help.** `check → DB::transaction → lockForUpdate → re-check → mutate → commit`; no network call inside a lock; unique constraints before application checks. There is no idempotency mechanism, no optimistic locking, and only 3 real lock sites in `app/`.
7. **The do-not-touch list, and the pre-existing baseline.** 43 tables and every financial/pricing/booking/availability behaviour are off limits. The suite is 673 passed / 2 failed / 1 warning / 4 skipped, and those 2 failures are pre-existing — report them, never absorb them.
8. **Open decisions are not yours to close.** No commission percentage, revision limit, dispute window, payout cadence, tax rule, currency or identifier strategy is decided. If your work needs one, it is `[OPEN DECISION]`: state what is missing and ask.
9. **How the platform actually behaves where you expect it not to.** Storage is effectively public with no signed URLs; tokens never expire; `FileController` upload has no validation; role values written in code are often unseeded and silently grant nothing; 384 of 385 routes are unthrottled. Verify before you rely on anything.
10. **How to finish a change.** Run `ArchitectureRuleTest`, `route:list`, `composer dump-autoload`, the full `php artisan test`, and `pint --test` on the files you touched; report exact counts; list deferred findings; and if you believe you must touch something on the do-not-touch list, **stop and escalate** rather than proceeding.

---

## 30. Final readiness assessment

| Area | Current State | Target State | Creative Reuse | New Work | Risk |
|---|---|---|---|---|---|
| Authentication | Sanctum + session, tokens never expire | Unchanged | Direct | None | Low |
| User / Profile | `users` + free-text single role | Unchanged; anchored by `creators.user_id` | Direct | `creators` | **Medium — dual role undecided** |
| Creator | None | 9-state aggregate + approval | Pattern (`screen_providers`) | Whole aggregate | Medium |
| Creative Services | None | Catalogue with rate + taxonomy | Pattern (unique pivot) | 4 tables + aggregate | Medium |
| Brief | None | Authoring + attachments | New | Whole | Low |
| Proposal | None | Offer + server-derived quote | New | Whole | **High — price integrity** |
| Project | None | Engagement + frozen terms | Pattern (immutability) | Whole | **High** |
| Revision | None | Bounded, auditable rounds | New | Whole | **High — limit undecided** |
| Delivery | None | Submission + acceptance | Pattern (`booking_media` review fields) | 2 tables + aggregate | **High — file exposure** |
| Review | `testimonials` (marketing) | Immutable, bound to project | **Do not reuse** | Whole | High |
| Earnings | `partner_commissions` (affiliate) | Accrual + reversal | Pattern (lifecycle + lock) | Whole | **Critical** |
| Payments | `payments` bound to bookings | Creative funding over the same contract | **Contract only** | 3 tables + use case | **Critical** |
| Commissions | 10.00/90.00 provider split | Versioned Creative rate | Do not inherit | Whole | **High — undecided** |
| Settlement | `screen_provider_settlements`, no currency | Creator payout batch **with** currency | Pattern (shape) | 3 tables + job | **High — undecided** |
| Storage | Public disk, no signed URL, unvalidated upload | Record + visibility + policy | Disks + image processing | Access mechanism | **P0** |
| Notifications | 1 subscriber, 0 listeners, no `creator` | +13 events, +Mailables | Extend | Mailables | Medium |
| Reporting | Flat advertising analytics | Creative queries over canonical records | New | Queries | Medium |
| Authorization | 5 roles, ~70 permissions, 0 policies, `settlements.*` ungranted | +role, +grants, +6 policies | Vocabulary + checker | Policies | **High** |
| API | Unversioned, 5 FormRequests, integer ids | `/api/creative/*`, FormRequests + Resources, public ids | Convention | All routes | Medium |
| Database | 73 tables, 96 FKs, 4 unconstrained columns | +16–19 tables, 0–5 rows added | 5 tables by reference | All schema | **High** |
| Testing | 85 files, 10 golden, 1 architecture | +12 Creative families | Reuse harness | All tests | Medium |
| Money type | Float-based conversion helper | `decimal(12,2)` + currency | At gateway only | Domain money type | **High — undecided** |
| Concurrency safety | 3 lock sites, no idempotency | Per-aggregate design | Lock/transaction shape | All guarantees | **Critical** |

### Readiness classification

| Area | Classification |
|---|---|
| Authentication | **READY** |
| Storage (disks, image processing) | **READY** |
| Notifications (dispatch path) | **READY** |
| Settings (store) | **READY** |
| Geography | **READY** |
| Payments (gateway contract) | **REUSE WITH ADAPTER** |
| Currency / FX | **REUSE WITH ADAPTER** |
| Audit trail | **REUSE WITH ADAPTER** (pending OD-AUDIT) |
| Reporting | **READY WITH REFACTOR** |
| User / Profile | **READY WITH REFACTOR** (dual role undecided) |
| Authorization | **READY WITH REFACTOR** (single-slug model; `settlements.*` ungranted; no finance role) |
| API | **READY WITH REFACTOR** (convention deviations required and recorded) |
| Database | **READY WITH REFACTOR** (additive migrations only) |
| Testing | **READY** (harness fully reusable) |
| Creator | **NEW IMPLEMENTATION** |
| Creative Services | **NEW IMPLEMENTATION** |
| Brief | **NEW IMPLEMENTATION** |
| Proposal | **NEW IMPLEMENTATION** |
| Project | **NEW IMPLEMENTATION** |
| Revision | **NEW IMPLEMENTATION** |
| Delivery | **NEW IMPLEMENTATION** |
| Review | **NEW IMPLEMENTATION** |
| Earnings | **NEW IMPLEMENTATION** |
| Commissions | **OPEN DECISION** |
| Settlement | **OPEN DECISION** |
| Creative domain boundary (separate project vs context) | **OPEN DECISION** |
| Money type (Creative value object vs shared helper upgrade) | **OPEN DECISION** |
| Identity model (dual role) | **BLOCKED — blocks C1** |

### Overall verdict

`[RECOMMENDATION]` **BLOCKED on one decision, otherwise READY TO BUILD behind a boundary test.**

The repository is a sound foundation for a Creative bounded context: identity, a gateway contract, storage disks, image processing, FX, notifications, an audit table, a configuration store, a golden regression suite and a static architecture guard are all reusable as-is. Nothing about Creative requires touching advertising behaviour, and the one place where reuse would be most tempting — the `payments` table and the settlement service — is precisely where the audit shows reuse would be wrong.

The single blocker is not technical: someone must answer whether one person may be both an advertiser and a creator. Until then, C1 (module root plus the boundary test that fences Creative in) is the only work that can start, and the boundary test is the most valuable thing in the whole plan — it is what makes the other twelve phases safe to attempt.

---

## Appendix A — Artifact index

| # | File | Content |
|---|---|---|
| 01 | `01-current-backend-inventory.json` | Measured inventory, layers, routes, tables, tests, integrations, discrepancies |
| 02 | `02-current-architecture-assessment.md` | What the architecture actually is, and where it is violated |
| 03 | `03-current-to-target-architecture-map.json` | Class-level current → target mapping |
| 04 | `04-creative-existing-capability-audit.json` | Exhaustive Creative keyword sweep; 16 concept verdicts |
| 05 | `05-shared-capability-reuse-matrix.json` | 24 shared capabilities, reuse strategy each |
| 06 | `06-creative-domain-boundary-map.json` | 9 aggregates, domain services, 13 events, financial boundary |
| 07 | `07-creative-business-flow-map.md` | The 18-step lifecycle with actors, rules and failure paths |
| 08 | `08-creative-database-current-schema-map.json` | All 73 tables, conventions, per-table Creative notes |
| 09 | `09-creative-database-target-schema-blueprint.json` | Proposed Creative schema, order, rejections, open decisions |
| 10 | `10-existing-table-reuse-analysis.json` | Every table: reuse / extend / pattern / do-not-use + risk |
| 11 | `11-existing-service-reuse-analysis.json` | All 48 services: dependencies, transactions, Creative verdict |
| 12 | `12-payment-financial-reuse-analysis.md` | Gateway, money, records, three-way separation, open decisions |
| 13 | `13-authorization-audit.md` | Roles, permissions, middleware, policies, weaknesses |
| 14 | `14-api-architecture-audit.md` | Conventions, envelopes, pagination, ids, throttling; recommendation |
| 15 | `15-state-machine-analysis.json` | 9 lifecycles, transitions, actors, effects; reuse verdict |
| 16 | `16-transaction-concurrency-analysis.json` | Current transaction/lock inventory; 9 Creative critical operations |
| 17 | `17-storage-asset-reuse-analysis.md` | Disks, uploads, file metadata; what Creative must build |
| 18 | `18-event-notification-analysis.json` | Current events, dispatch, dedup; 13 proposed Creative events |
| 19 | `19-creative-security-risk-register.json` | 25 risks (12 P0, 10 P1, 3 P2), none remediated |
| 20 | `20-creative-target-module-structure.md` | The physical target tree, with reasoning per entry |
| 21 | `21-creative-domain-ownership-matrix.json` | Capability → owner (domain, DB, application, infrastructure) |
| 22 | `22-creative-dependency-rules.md` | The rule set a Creative developer must follow, with checks |
| 23 | `23-creative-do-not-touch-boundaries.md` | Justified do-not-touch list and escalation procedure |
| 24 | `24-creative-implementation-sequence.md` | C0–C13 with dependencies, work, endpoints, tests, completion criteria |
| 25 | `25-creative-testing-strategy.md` | 12 test families, reusable infrastructure, phase-to-test map |
| 26 | `26-creative-reuse-vs-new-development-matrix.json` | 40 requirements → reuse type, target, new work, risk |
| 27 | `27-creative-marketplace-audit-report.md` | This document |

## Appendix B — Verification performed by this audit

```text
php artisan test                                  673 passed, 2 failed, 1 warning, 4 skipped (5564 assertions)
                                                  both failures pre-existing: BookingConcurrencyTest.php:371,
                                                  CampaignBookingPricingIntegrityTest.php:228 (message-wording drift)
tests/Unit/Architecture/ArchitectureRuleTest.php   14 passed
php artisan route:list                            385 routes (unchanged)
composer dump-autoload                            not required: no PHP file was created, moved or renamed
./vendor/bin/pint --test                          fails repository-wide (pre-existing); no PHP file was touched
python3 -m json.tool on all 15 JSON artifacts     valid
```

Measurements taken directly from source: 112 migrations, 73 distinct tables, 96 foreign-key definitions, 385 routes, 48 services (11 926 LOC, 41 importing `App\Models`, 14 with a real `DB::transaction(`, 1 with a real `->lockForUpdate(`), 3 real `lockForUpdate` call sites in `app/`, 24 files with a real `DB::transaction(` at 41 sites, 6 controllers calling `Storage::` directly, 0 policies, 0 listeners, 0 jobs, 0 signed URLs, 0 `afterCommit`, 0 idempotency mechanisms, 0 version columns.

`[CURRENT FACT]` `git status` confirms no production file was modified by this audit. The only changes in the working tree are the three pre-existing modifications from an earlier governance task, the two new root documents `AGENTS.md` and `SKILLS.md` (with two factual corrections recorded in §16 and §14 of `AGENTS.md`), and the 27 files in `docs/audit/creative-marketplace/`. A test run additionally wrote one stray image artefact to `tests/compressed/`; it was **not** deleted, because deletion is forbidden in this audit, and it is recorded in artifact 25.

## Appendix C — Statements this audit explicitly does not make

- It does not say any Creative rule is decided. Thirteen decisions are open (§27).
- It does not propose a commission percentage, a revision limit, a dispute window, a payout cadence, a tax rule or a currency policy. Each is `[OPEN DECISION]` with the missing information named.
- It does not claim any Creative code, table, route or test exists. None does.
- It does not treat the Phase 0.18/0.20 "CREATED (empty)" Creative directories as evidence. They do not exist.
- It does not fix any defect found. Twenty-odd platform findings are recorded with file:line and left in place, per `AGENTS.md` §18.
