# AGENTS.md — Illuvision Core Backend

**Status:** Permanent, repository-level agent governance. Mandatory for every AI coding agent and every human contributor working in this repository.

**Scope:** The entire repository, at any depth.

**Companion document:** `SKILLS.md` (engineering capabilities and preferred implementation patterns). `SKILLS.md` never overrides this file; where the two conflict, this file wins.

**Precedence:** The nearest applicable `AGENTS.md` refines this policy. It can never override root security, financial, database, API-contract, architecture, or production-safety constraints unless this root document explicitly delegates that decision. See §22.

---

## 1. Project Identity

```text
Project:        Illuvision Core Backend
Repository:     illuvision/core-backend
Primary purpose:
                Backend / API platform for the Illuvision Advertising Marketplace.
Stack:          Laravel 11 (PHP ^8.2), MySQL, Sanctum, Redis/queue, Razorpay SDK,
                maatwebsite/excel, phpoffice/phpword.
Architecture:   Modular monolith (single Laravel application, single database).
                See docs/architecture/ARCHITECTURE_RULES.md, Rule 8.
Current scope:  Project 01 — Advertising Marketplace backend.
```

This repository is **not** the CMS backend. It is the commercial system of record for the advertising marketplace.

### Project boundaries

```text
Project 01 — Advertising Marketplace   <-- THIS REPOSITORY
        |
        v
Project 02 — Creative Marketplace
        Initialised as a bounded Creative domain inside Project 01
        (app/Domain/Creative, app/Services/Creative — capability declared, not yet implemented)
        |
        v
Project 03 — CMS SaaS
        Separate Laravel application
        Separate MySQL database
        Separate bounded system
```

Creative is deliberately a bounded domain inside Project 01 first. The CMS (Project 03) is a separate application and a separate database.

**Agents must not create CMS implementation inside this repository.** No CMS controllers, no CMS models, no CMS migrations, no CMS tables, no CMS routes.

---

## 2. Business Context

The commercial lifecycle the backend represents:

```text
Discover Media -> Plan Campaign -> Create/Obtain Creative -> Book Media
               -> Pay -> Deliver Campaign -> Measure/Report
```

This application is the system of record for:

- media inventory and availability;
- campaign planning;
- campaign booking;
- pricing and pricing rules;
- payments, refunds and payment state;
- provider commercial relationships;
- partner commercial relationships and commissions;
- settlement;
- reporting and analytics;
- the Creative marketplace bounded domain (initialised, see §8).

Project 01 is the commercial foundation. The CMS (Project 03) is the operational platform built on top of it. Business behaviour originates here; the CMS consumes it through versioned contracts.

---

## 3. Core Architectural Principle

```text
REFACTOR THE IMPLEMENTATION, NOT THE EXTERNAL CONTRACT.
```

This restates and enforces `docs/architecture/ARCHITECTURE_RULES.md` Rule 5. Unless a task explicitly authorises a contract change, agents must preserve:

- API routes, URIs and HTTP methods;
- request field names, types and validation semantics;
- response structure, field names, status codes and error envelopes;
- authentication behaviour and token semantics;
- authorization semantics, including deliberate asymmetries;
- financial calculations, rounding and currency conversion;
- database schema, indexes and foreign keys;
- transaction boundaries and concurrency semantics;
- event contracts, queued job payloads and job names;
- scheduler registrations and command signatures.

### The security exception

If an existing behaviour is explicitly established as a security defect, the agent **may** intentionally change it. That path is mandatory, not optional:

```text
characterize current behaviour -> fix -> test -> document -> verify
```

Characterization tests must be written **before** the fix, so the pre-fix behaviour is pinned in the report and the post-fix behaviour is pinned in the suite. Every such change must be reported explicitly (§24).

---

## 4. Approved Target Architecture

The approved Project 01 structure (target only). The current state is recorded in
"Current reality" below; several Phase 0.20 artifact rows are known to be wrong
about what exists on disk:

```text
app/
├── Domain/                 # pure business rules, value objects, domain contracts
│   ├── Campaign/
│   ├── Media/
│   ├── Asset/
│   ├── Provider/
│   ├── Partner/
│   ├── Pricing/
│   ├── Availability/
│   ├── Booking/
│   ├── Payment/
│   ├── Settlement/
│   ├── Reporting/
│   └── Creative/
│
├── Services/               # application / use-case orchestration
│   ├── Campaign/
│   ├── Booking/
│   ├── Pricing/
│   ├── Payment/
│   ├── Creative/
│   └── Settlement/
│
├── Repositories/           # repository contracts + persistence-facing abstractions
│   ├── Campaign/
│   ├── Asset/
│   ├── Booking/
│   ├── Pricing/
│   ├── Payment/
│   └── Creative/
│
├── Infrastructure/         # adapters, vendors, drivers
│   ├── Persistence/
│   ├── Payments/
│   ├── Storage/
│   ├── Notifications/
│   ├── ExchangeRates/
│   └── ExternalServices/
│
├── Http/                   # Controllers, Requests, Resources, Middleware
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
│
└── Support/                # genuinely cross-cutting Events, Jobs, Exceptions, Authorization
    ├── Events/
    ├── Jobs/
    └── Exceptions/
```

### Current reality — read this before you plan

The repository is mid-migration. Several directories in the target structure exist but are intentionally empty (capability declared, not yet implemented), and several legacy locations are still deliberately retained. Agents must not assume the target structure is already populated, and must not treat emptiness as a defect to fix opportunistically.

Observed state (Phase 0.20 artifacts, class counts re-verified 2026-09-26):

```text
app/Domain/            present, partially populated: Asset, Availability, Booking,
                       Financial, Payment, Pricing (+ empty capability dirs)
app/Services/          present, 48 classes: 28 flat at the root (recorded debt)
                       plus 20 in capability subdirs (Campaign, Booking, Pricing,
                       Payment, Settlement)
app/Repositories/      present: Asset/, Booking/ (contracts + implementations)
app/Infrastructure/    present: Auth, ExchangeRates, Financial, Media,
                       Notifications, Payments, Storage
app/Http/              present: Controllers (+ Api, Auth), Middleware, Requests, Resources
app/Support/           present: Authorization, Configs, Events, Exceptions (+ 2 root classes)
                       NOTE: app/Support/Jobs (target tree) does NOT exist.
app/Application/       retained and being retired; 32 classes deferred
app/Models/            62 classes (retained, justified)
app/Mail/              28 classes (retained, justified)
app/Enums, app/Helpers, app/Observers, app/Providers, app/Console
                       retained, justified
```

Current scale figures used as governance baselines. Re-measure before quoting them;
these were verified on 2026-09-26 (routes and migrations re-measured, database figures
carried from `docs/audit/phase-0.20-verification-report.md`):

```text
385 routes | 112 migrations | 73 tables | 96 foreign-key definitions | 0 views
```

Re-measured 2026-09-26 from the 112 migrations: **73 distinct tables** (75 `Schema::create` calls — `pricing_peak_rules` and `pricing_weekend_rules` are each created twice) and **96 foreign-key definitions** (94 `->constrained()` plus 2 legacy `->foreign()`). The previously recorded "74 tables / 94 foreign keys" overstates tables by the 2 duplicate creates, and its 94 was the `->constrained()` count alone. Source wins over the earlier figure.

---

## 5. Dependency Direction

Canonical flow (`ARCHITECTURE_RULES.md` Rule 11):

```text
HTTP
  -> Application / Services (use cases)
    -> Domain
      -> Repository Contracts
        -> Repository Implementations
          -> Infrastructure / Persistence
            -> MySQL / External systems
```

External integrations:

```text
Application / Domain
  -> Contract (interface)
    -> Infrastructure Adapter
      -> External system
```

Rules that follow directly:

- an inner layer may never reference an outer layer's implementation;
- `Domain` and `Application` must not import `App\Http`;
- a Model (Active Record) must not reference the HTTP layer;
- `Application` may orchestrate legacy `App\Services` only in the transitional strangler state (Rule 7) — never the reverse;
- where a boundary is genuinely mis-modelled, **fix the dependency, do not relocate the file**. Moving a class that takes Eloquent models into `Domain` converts a visible violation into a subtler one.

### Enforced debt, not silently tolerated debt

`tests/Unit/Architecture/ArchitectureRuleTest.php` statically enforces the rules using PHP's token stream (not regex) and pins known pre-existing violations **exactly** (file + method + line). Adding an eighth `Domain -> Services` import fails the suite; silently dropping one also fails, because it means the debt register is stale.

Current pinned debt:

- `App\Domain\Pricing\CampaignPricingEngine` imports 7 legacy `App\Services\*` classes (Rule 11 debt, recorded in `docs/audit/phase-0.14-architecture-debt-register.json`).
- 6 Domain files still import `App\Models` (9 import edges) — tracked in `docs/audit/phase-0.20-boundary-violations.json`, **not** yet enforced by the test.
- `Support -> Services` imports (e.g. `Support/Configs/* -> SettingsService`).
- 11 service-locator call sites (`app(...)` / `resolve(...)` inside business logic) — tracked in `docs/audit/phase-0.20-service-locator-usage.json`.

**Do not "helpfully" fix, remove, or re-pin any of this.** It is controlled, documented debt with a scheduled migration order (`docs/audit/phase-0.20-migration-order-plan.md`). See §19.

---

## 6. Layer Responsibilities

### `Domain/`

Contains business rules, domain concepts, value objects, domain contracts, domain states, and domain services where appropriate. Pure and deterministic (`ARCHITECTURE_RULES.md` Rules 2 and 9): no network calls, no HTTP, no session, no transaction orchestration.

`Domain` must not depend on `App\Http`, on infrastructure implementations, on third-party SDKs, or on Eloquent persistence.

`Domain -> App\Models` is prohibited **except** for entries on a documented temporary allowlist. Today that allowlist is 6 files / 9 import edges recorded in the Phase 0.20 artifacts; new ones are forbidden, and removing an existing one requires the repository-extraction work that makes it safe.

### `Services/`

Application and use-case orchestration: coordinating domain operations, repositories, transactions, external contracts and workflows. Services are not a dumping ground. Do not move a domain rule into a Service merely because the current implementation happens to live in a `Service` class. During the strangler phases, Services may legitimately wrap legacy behaviour — wrap and delegate, do not duplicate (Rule 7).

### `Repositories/`

Repository contracts and persistence-facing abstractions. Introduce a repository only where persistence isolation, complex query encapsulation or testability is demonstrably justified (`ARCHITECTURE_RULES.md` Rule 6) — no blanket boilerplate for simple Active Record models.

Do not rename an Eloquent-heavy service to `Repository`. Extraction must genuinely separate business/application logic from persistence logic. 33 services doing Eloquent work directly are recorded as extraction candidates in `docs/audit/phase-0.20-repository-extraction-candidates.json`; treat that as a backlog, not a to-do list for the current task.

### `Infrastructure/`

Eloquent persistence, payment gateways (Razorpay), storage, notifications, exchange rates, media processing, auth adapters, third-party integrations. Infrastructure may depend on Laravel and vendor SDKs. Vendor SDK types must never leak into Domain or Application signatures (Rule 3). Vendor initialisation, exception handling and data structures belong here.

### `Http/`

`Controllers`, `Requests`, `Resources`, `Middleware`. Controllers stay thin:

```text
validate / authorize -> invoke application service or use case -> return response
```

No large business workflows in controllers (Rule 1: no direct SDK instantiation, no direct third-party HTTP from controllers). Note that `app/Http/Controllers` is currently flat; the naming convention for capability subdirectories is a convention, **not** a route boundary — do not move files into new subdirectories as part of an unrelated task.

### `Support/`

Genuinely cross-cutting concerns: `Events`, `Jobs`, `Exceptions`, `Authorization`, `Configs`. Do not park domain-specific business logic in `Support` merely because it is shared. `Support/Authorization/RolePermissionChecker` is the single owner of the permission algorithm (Rule 12) — it must not be re-implemented per caller.

---

## 7. Domain Ownership

Project 01 domain ownership:

```text
Campaign     Media        Asset        Provider      Partner
Pricing      Availability Booking      Payment       Settlement
Reporting    Creative
```

Every new class must have one clear domain owner.

Agents must not introduce vague umbrella directories such as:

```text
Core/  Business/  Managers/  Components/  CommonBusiness/  Misc/  Utils/  SharedLogic/
```

unless an explicit, recorded architecture decision authorises them. If ownership is genuinely unclear, see §20: do not guess, and do not invent a new layer to avoid the decision.

---

## 8. Creative Domain

Creative is a bounded domain inside Project 01, **declared capability only, not yet scaffolded**.

```text
app/Domain/Creative/        <- DOES NOT EXIST YET
├── Creator/     ├── Brief/       ├── Project/
├── Service/     ├── Proposal/    ├── Revision/
├── Delivery/    ├── Review/      └── Earnings/
```

```text
[CURRENT FACT — verified 2026-09-26]
None of app/Domain/Creative, app/Services/Creative, app/Repositories/Creative
or app/Http/Controllers/Creative exists on disk. `find app -type d -empty`
returns nothing: the repository contains no empty capability directories at all.

Several Phase 0.18/0.20 audit artifacts and layer READMEs assert these
directories were "CREATED (empty)"; that claim is FALSE and is recorded as a
documentation discrepancy in docs/audit/creative-marketplace/.

Consequence: Creative is 100% greenfield. Creating these directories is a
Creative task, not an unrelated side effect. Any agent that needs a Creative
class must create the module root deliberately and document the decision.

Do not create a second Laravel Creative backend in this repository. Do not
populate these directories as part of an unrelated task.
```

---

## 9. CMS Boundary

```text
CMS is NOT part of this repository.
```

Project 03 is a separate Laravel application with a separate MySQL database and a separate bounded context. There is no shared database between Marketplace and CMS.

Marketplace <-> CMS communication must occur through:

```text
Versioned APIs | Events | Webhooks
Authentication | Idempotency | External IDs
```

Never access CMS tables directly from this application. Never add a cross-database join, foreign key, or migration against the CMS database. Never introduce a direct synchronous call from a Marketplace transaction into the CMS.

---

## 10. Financial Safety Rules

## Financial Code Is High Risk

Financial domains in this repository:

```text
Pricing | Payment | Revenue Distribution | Partner Commission
Settlement | Refund | Financial Reporting
```

Agents must never casually change:

- price calculations or pricing-rule evaluation;
- rounding behaviour, scale, or rounding direction;
- currency conversion and exchange-rate application;
- provider revenue, partner commission, revenue split resolution;
- payment amounts, payment status transitions, idempotency keys;
- settlement amounts and settlement periods;
- refund amounts and refund allocation;
- any financial snapshot or historical financial record.

Financial history is immutable. Fix forward with compensating records; never rewrite a stored financial fact.

### Required sequence before modifying financial code

```text
identify current behaviour
  -> inspect the golden/financial tests that pin it
    -> inspect the transaction boundary
      -> inspect persistence and rounding at the point of write
        -> make the minimal change
          -> run the financial regression suite
```

Never "clean up", reformat, rename, or optimise financial logic during an unrelated task.

Mandatory financial regression suites:

```text
tests/Feature/Golden/GoldenFinancialRegressionTest.php
tests/Feature/Golden/GoldenPricingRegressionTest.php
tests/Feature/Golden/GoldenPaymentRegressionTest.php
tests/Feature/Golden/GoldenFinancialBoundaryTest.php
tests/Feature/Golden/GoldenPaymentBoundaryTest.php
tests/Feature/FinancialRegressionAndHardeningTest.php
```

Floating-point arithmetic must never be introduced for monetary values. Follow the existing money representation and the existing rounding convention exactly; do not migrate the representation as part of an unrelated change.

---

## 11. Booking, Availability and Concurrency Rules

Booking is concurrency-sensitive and inventory-bearing.

```text
availability check  !=  transactional reservation
```

The protected shape is:

```text
check -> DB::transaction -> lockForUpdate() -> re-check availability -> mutate -> commit
```

Agents must not:

- move `lockForUpdate()` outside a transaction;
- change lock acquisition order casually (deadlock risk);
- remove a re-check inside the transaction;
- merge availability and reservation logic without explicit verification;
- alter transaction boundaries during a cosmetic refactor;
- hold a row lock across a network call (Rule 4: isolate external operations outside locks to avoid lock timeouts and connection-pool exhaustion).

Before changing booking or availability code, inspect all of:

```text
transaction boundary | lockForUpdate | availability recomputation
conflict detection | pricing | booking snapshot | events
payment transition | pending-booking expiration
```

Mandatory suites: `tests/Feature/Golden/GoldenBookingRegressionTest.php`, `tests/Feature/Golden/GoldenAvailabilityRegressionTest.php`, `tests/Feature/Golden/GoldenConcurrencyVerificationTest.php`, `tests/Feature/BookingConcurrencyTest.php`, `tests/Feature/AvailabilityApiAndPerformanceHardeningTest.php`.

Concurrency changes additionally require reasoning about deadlocks, lock ordering, and the concurrent path being exercised — not only the happy path.

---

## 12. Authorization Rules

Authentication and authorization are separate concerns and must be reported separately:

```text
Unauthenticated            -> 401
Authenticated, unauthorized -> 403
```

Never assume:

```text
auth middleware present == permission to perform the operation
```

Authoritative facts for this repository:

- Authorization is enforced at the route/middleware boundary (`ARCHITECTURE_RULES.md` Rule 12). This is the authoritative check.
- The permission algorithm has exactly one owner: `app/Support/Authorization/RolePermissionChecker`, shared by every caller. It must never be duplicated per caller and never owned by the transport layer.
- Deliberate asymmetries between the authoritative route check and convenience helpers — for example a super-admin bypass that exists **only** in middleware — are intentional. Preserve them exactly and keep them pinned by tests until an explicit authorization change is approved. Altering them changes authorization behaviour.
- Every administrative and financial write requires an appropriate authorization decision.

Do not weaken permissions, widen role capability, or relax guards to make a test pass. If a test fails because a guard is correct, fix the test or report the finding.

Authorization changes must be verified with both allowed and denied paths, including negative and privilege-escalation cases (`tests/Feature/Security/`, `tests/Feature/AuthorizationHardeningTest.php`).

Security remediation is the one permitted intentional behaviour change — follow the §3 sequence and report it.

---

## 13. API Contract Rules

Unless explicitly authorised, do **not** change:

```text
URI                     HTTP method           request fields
response fields         status semantics      error envelope
authentication contract authorization contract pagination semantics
```

A refactor that appears to *require* a contract change must:

```text
STOP -> report the blocker and the required contract delta -> wait for an explicit decision
```

Do not silently "improve" a response shape, rename a field, add a required parameter, or change a status code.

Verification aids: `docs/audit/api-contract-baseline.json`, `docs/audit/golden-api-contracts.json`, `docs/audit/contract-drift-matrix.json`, `docs/audit/routes-baseline.json`, and `tests/Feature/Golden/GoldenApiContractRegressionTest.php`. `tests/Feature/Golden/GoldenApiContractRegressionTest.php` must stay green; route count parity is the minimum bar for any routing change (currently 385).

---

## 14. Database Rules

Default assumption:

```text
application refactoring  !=  database redesign
```

Do not, unless the task explicitly authorises schema work:

- modify or edit an already-applied migration;
- add a destructive migration (drop column/table, truncate, data loss);
- rename tables or columns;
- remove indexes or foreign keys;
- change column types on financial or identity columns;
- add cross-database foreign keys.

Reference: 73 tables, 96 foreign-key definitions (94 `->constrained()` + 2 legacy `->foreign()`), 112 migrations, 0 views. Any deviation is a schema change and must be reported as such.

---

## 15. Migration and Code-Movement Rules

When moving or renaming code:

```text
inventory -> classify responsibility -> identify consumers -> move
          -> update namespace -> update imports (explicitly) -> update bindings
          -> resolve all consumers -> verify container -> run tests
```

Never assume:

```text
grep found no reference to the old namespace == safe migration
```

Short-name resolution breaks when namespace siblings become different directories. `grep`-clean is a hypothesis; container resolution and route parity are the proof.

Always perform consumer and container verification (see §17) and record the movement in the phase ledger.

---

## 16. Dead Code and Deletion Rules

Never delete code solely because `grep` finds no reference. Deletion requires **both** checks to pass (`ARCHITECTURE_RULES.md` Rule 13):

1. **Reference counting** across `app/`, `routes/`, `tests/`, `database/`, `config/`, `bootstrap/`, `resources/`.
2. **Framework-registration verification** against `php artisan list`, `routes/console.php`, the route table, event/listener registrations, scheduled tasks, Artisan commands, policies, service providers, and Eloquent relations.

Laravel resolves console commands, scheduled tasks, listeners, relations, policies and providers by convention, not by import. This repository has already demonstrated the failure mode: a purely lexical scan flagged three **live** scheduled console commands (`rewards:expire`, `bookings:expire-pending`, `currency:sync-rates`) as removable. Also check class names stored in database columns and dynamically resolved class references before deleting anything.

A rule implementation may not be removed until its canonical owner is explicitly designated.

Dead-code analysis is recorded per phase (`docs/audit/phase-0.13-dead-code-candidates.json`, `docs/audit/phase-0.17-dead-code-verification.json`, `docs/audit/phase-0.17-deletion-ledger.json`). Deletion requires an explicit task; opportunistic deletion is forbidden.

---

## 17. Required Verification

Baseline commands for this repository:

```bash
composer dump-autoload
php artisan test
php artisan route:list
./vendor/bin/pint --test        # style, when touching PHP
```

`./vendor/bin/pint --test` currently **fails repository-wide** (hundreds of pre-existing files). That is the existing state, not a regression from your change. Do not run `pint` over the repository to "fix" it as part of an unrelated task (§18). Apply style fixes only to files your task already touches, and report the pre-existing failure.

Full-suite reference state (Phase 0.20 / current): **673 passed, 2 failed, 1 warning, 4 skipped, 5564 assertions**. The 2 failures and 1 warning are pre-existing and must not be silently absorbed into a new change:

- `Tests\Feature\BookingConcurrencyTest` — expects "no longer available"; actual message is "... has only N units available, but M requested."
- `Tests\Feature\CampaignBookingPricingIntegrityTest` — same message-wording drift.
- `MediaHelperTest` — tautological assertion warning.

If your change alters these outcomes, that is a reportable change, not a silent improvement.

### Select verification by change type

**Architecture / code movement** — mandatory:

```text
tests/Unit/Architecture/ArchitectureRuleTest.php
php artisan route:list            (route parity)
composer dump-autoload            (autoload + container resolution)
php artisan test
```

**Financial changes** — pricing, payment, financial, settlement, refund, commission, currency tests (§10).

**Booking / availability changes** — booking, availability, concurrency, lock verification (§11).

**Authorization changes** — authentication, authorization, negative and privilege-escalation tests (§12).

**External integration changes** — adapter contract tests plus the calling feature tests; no real network calls in tests.

**Documentation-only changes** — no test run required, but the change must be accurate: read it back and confirm every factual claim against the repository (§21).

---

## 18. Do Not Fix Unrelated Problems

An assigned task is not permission for unrelated cleanup.

If another issue is discovered, **record it** rather than expanding scope. Only act when:

- it blocks the assigned task;
- it is a direct, unavoidable consequence of the assigned task;
- it is a critical security issue (report it prominently, and get authorisation before a broad fix);
- the task explicitly includes it.

Do not reformat, rename, reorder, or "modernise" files you are not otherwise changing. A diff that is larger than the task requires is a finding to report, not a quality signal.

---

## 19. Existing Debt Is Not Permission to Refactor

This repository contains substantial, deliberate, documented debt. Examples:

```text
Domain -> Models (6 files, 9 import edges, tracked, unenforced)
Domain -> Services (7 pinned imports in CampaignPricingEngine)
Support -> Services imports
27 flat root-level Services classes (Phase 0.20 count; P3/P4 financial and
transactional classes held back on purpose)
32 deferred app/Application classes (layer being retired)
11 service-locator call sites
33 repository-extraction candidates
2 pre-existing test failures + 1 warning
```

Do not automatically resolve debt you encounter. The migration has a defined order (`docs/audit/phase-0.20-migration-order-plan.md`, `docs/audit/phase-0.20-file-movement-ledger.md`) and defined priorities (P1/P2/P3/P4). Respect the current phase. Resolving out-of-order debt creates unreviewed behavioural risk in exactly the areas the phase plan is protecting.

---

## 20. Architecture Decision Rule

If the correct location or ownership of a class is unclear: **do not guess.**

Perform:

```text
responsibility analysis
  -> dependency analysis (imports, container bindings, event/job references)
    -> consumer analysis (routes, tests, migrations, config, scheduled tasks)
      -> comparison with the architecture documents
```

Then either make a **documented** decision, or defer it and record the decision as deferred. Both outcomes are acceptable; an undocumented guess is not.

Do not create a new architectural layer to avoid making the decision.

---

## 21. Documentation Authority

Before architectural work, read:

```text
docs/architecture/ARCHITECTURE_RULES.md      # normative rules; wins over any plan text
docs/audit/                                  # per-phase maps, diffs, ledgers, reports
docs/audit/architecture-decisions.md
docs/audit/architecture-migration-ledger.json
docs/audit/architecture-migration-order.json
docs/sprint_*_architecture.md                 # sprint architecture context
```

Authority order:

1. `docs/architecture/ARCHITECTURE_RULES.md`;
2. the newest phase artifacts in `docs/audit/` — a later phase explicitly supersedes an earlier phase's assumptions where it says so;
3. `README.md` files inside `app/` layers (layer intent);
4. `docs/sprint_*` narratives.

If documentation conflicts with the code, or with itself:

```text
STOP -> identify the conflict -> do not silently choose
```

Report the conflict and the options. Measurement wins over narrative when the two disagree; a documented decision wins over an undocumented assumption.

---

## 22. Nested `AGENTS.md` Rule

This is critical.

Before modifying files in any directory, an agent must check for an `AGENTS.md` in that directory and every directory above it, and read the nearest one.

```text
/AGENTS.md                       root policy (this document)
    |
    v
/app/AGENTS.md                   layer-level refinement
    |
    v
/app/Domain/Booking/AGENTS.md    directory-level refinement
```

A nested file **may**:

- add rules for its scope (naming, local conventions, extra verification, local pitfalls);
- state additional prohibitions;
- document local history and the local migration plan;
- require stricter tests than the root.

A nested file **must not** override root-level rules on:

```text
security | financial safety | database safety
API contract preservation | architecture boundaries | production safety
```

If a conflict exists:

```text
ROOT POLICY WINS
```

unless this root document explicitly delegates that specific decision to a nested scope. Delegation must be explicit, written here, and narrow.

Nested files are additive by default. When in doubt, satisfy both documents.

---

## 23. Agent Task Workflow

```text
1.  Read root AGENTS.md
2.  Find and read every applicable nested AGENTS.md
3.  Read SKILLS.md and select the relevant skills
4.  Read the relevant architecture documentation
5.  Read the relevant phase artifacts for the current phase
6.  Inspect the existing implementation before designing anything
7.  Identify dependencies (imports, container, events, jobs, scheduler)
8.  Identify contracts (API, financial, authorization, event)
9.  Identify risks (financial, concurrency, security, data)
10. Plan the minimal change
11. Implement
12. Run targeted tests
13. Run architecture verification
14. Run the broader regression suite
15. Review the diff line by line
16. Report (§24)
```

If step 10 cannot be completed without a decision the agent is not authorised to make — stop and ask.

---

## 24. Required Agent Report

Every substantial task ends with a report containing:

```text
What changed
Why it changed
Files changed
Architecture impact
API impact
Database impact
Financial impact
Security impact
Tests run (exact commands)
Tests passed / failed (with the exact counts)
Remaining risks
Deferred findings
```

Rules for the report:

- Never claim tests were run if they were not.
- Never claim architecture conformance without running `ArchitectureRuleTest` (and stating its result).
- State pre-existing failures as pre-existing.
- Disclose any intentional behaviour change, and its authorisation.
- List deferred findings from §18 explicitly, with file references.
- If a documentation file was written, confirm it was read back and checked against the repository.
