# 22 — Creative Dependency Rules

**Generated:** 2026-09-26
**For:** the engineer who will implement Creative Marketplace (expected profile: junior developer working under review).
**Classification:** `[ARCHITECTURE DOCUMENT]` for every rule traceable to `docs/architecture/ARCHITECTURE_RULES.md` or root `AGENTS.md`. `[CURRENT FACT]` where the rule exists because of a verified property of this codebase. `[RECOMMENDATION]` where the rule is a Creative-specific addition to the project rules. `[OPEN DECISION]` where the rule cannot be settled without a product or architecture decision.

Each rule below is stated once, given its authority, given the **failure mode it prevents**, and given **how to check it**. A rule you cannot check is not a rule; it is a preference. Where a check is mechanical, it belongs in `tests/Unit/Architecture/ArchitectureRuleTest.php`, which already statically enforces the layer rules using PHP's token stream and pins known pre-existing violations exactly.

---

## Part A — Layer and dependency direction

### A1. Dependencies flow one way only

`[ARCHITECTURE DOCUMENT]` Rule 11:

```text
HTTP -> Application / Services -> Domain -> Contracts -> Infrastructure -> Persistence / External
```

**Prevents:** the gradual inversion of a modular monolith into an unmaintainable graph.
**Check:** token-level import assertions in `ArchitectureRuleTest`.

### A2. `Domain\Creative` must not import `App\Http`

`[ARCHITECTURE DOCUMENT]` Rule 11 names this explicitly. A domain class that knows about a request or a controller cannot be tested without HTTP.
**Prevents:** a "clever" domain service that reads `$request->user()` to decide who the creator is.
**Check:** grep for `App\\Http` inside `app/Domain/Creative` — must be zero. Add it to `ArchitectureRuleTest`.

### A3. `Domain\Creative` must not import `App\Services`

`[ARCHITECTURE DOCUMENT]` Rule 11. **This rule has teeth here, because the codebase already violates it 7 times:** `App\Domain\Pricing\CampaignPricingEngine` imports 7 legacy `App\Services\*` classes, pinned in `docs/audit/phase-0.14-architecture-debt-register.json` and enforced by `ArchitectureRuleTest`. That is **recorded debt, not a precedent.** A new Creative import of that kind fails the suite.
**Prevents:** the most common boundary erosion in this repository.
**Check:** `ArchitectureRuleTest` (already enforces the 7 pinned edges exactly — adding an 8th fails, silently dropping one also fails).

### A4. `Domain\Creative` must not import `App\Models`

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §6. Creative aggregate roots **are** Eloquent models living in `app/Domain/Creative/...`; they must not import *other* models. The Creative model is reachable from the aggregate root, not the other way round.
**[CURRENT FACT]` 6 Domain files still import `App\Models` (9 import edges), tracked in `docs/audit/phase-0.20-boundary-violations.json` and **not yet enforced**. That is why this rule must be enforced for Creative from the first commit rather than "cleaned up later".
**Prevents:** a `Proposal` that reaches into `App\Models\Booking` to price itself.
**Check:** grep `App\\Models` inside `app/Domain/Creative` — must be zero, enforced as a new ArchitectureRuleTest case.

### A5. No service locator in Creative business logic

`[CURRENT FACT]` 11 service-locator call sites (`app(...)` / `resolve(...)` inside business logic) are tracked in `docs/audit/phase-0.20-service-locator-usage.json`. `[RECOMMENDATION]` A Creative class resolves nothing at runtime except through constructor injection.
**Prevents:** hidden dependencies that no static check can see and no test can override.
**Check:** grep for `app(` / `resolve(` inside `app/Domain/Creative` and `app/Services/Creative`.

### A6. Fix a mis-modelled dependency; never relocate the file to hide it

`[ARCHITECTURE DOCUMENT]` Rule 11, final sentence. Moving a class that takes Eloquent models into `Domain` converts a visible violation into a subtler one.
**Prevents:** "domain purity" achieved by renaming namespaces.

---

## Part B — External systems and infrastructure

### B1. Creative domain code must not call HTTP clients, SDKs, mail or storage facades

`[ARCHITECTURE DOCUMENT]` Rules 1, 2 and 3. Forbidden inside `app/Domain/Creative` and, for SDK construction, inside `app/Services/Creative`:

```text
Http::            Razorpay\Api\...        new \GuzzleHttp\Client
Mail::            Storage::               file_get_contents on a remote URL
curl_*            config('services.razorpay') in a domain signature
```

**Prevents:** non-deterministic domain logic and vendor types leaking into application signatures.
**Check:** the exact greps listed in `AGENTS.md` §17 / the phase 0.12 validation set, scoped to `app/Domain/Creative` and `app/Services/Creative`.

### B2. Reuse `PaymentGatewayInterface`; never create a second gateway abstraction

`[CURRENT FACT]` `App\Domain\Payment\Contracts\PaymentGatewayInterface` already declares `createOrder`, `verifySignature`, `fetchPayment` and `refund`, implemented by `RazorpayPaymentGateway` — the only place the SDK appears.
**Prevents:** two payment stacks that drift, and a Creative payment path that bypasses the platform's verified flow.
**Check:** grep `Razorpay` outside `app/Infrastructure/Payments`.

### B3. Reuse the existing storage disks and image processing; never build a second storage architecture

`[CURRENT FACT]` `config/filesystems.php` provides `local` (`storage/app/private`), `public` (`storage/app/public`, `visibility => public`, url `APP_URL/storage`) and `s3`. `MediaHelper` compresses images through `ImageProcessingContractInterface`.
**Prevents:** duplicate infrastructure, and the temptation to "just make Creative files public" because that is the path of least resistance today.
**Check:** grep `Storage::` / `config('filesystems` outside `app/Infrastructure`.

### B4. Creative file **access** is a new capability and must be a contract plus an authorization check

`[RECOMMENDATION]` There is no signed-URL mechanism anywhere in this repository and no central file table. "Private Creative file" therefore means: a stored file plus a `CreativeFile` record plus a `CreativeFilePolicy` plus a service that authorizes before streaming. It is not a URL change.
**Prevents:** insecure direct object reference on client and creator files, which is the single highest-likelihood Creative security defect.

---

## Part C — Money, financial safety and contracts

### C1. Every Creative monetary amount is server-derived

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §10 (financial domains are high risk; the golden suites pin current behaviour). A client-supplied `amount`, `commission`, `status` or `earning` is a defect, not a feature.
**Prevents:** price manipulation, the highest-severity risk in the Creative register (artifact 19).
**Check:** the proposal/accept/fund handlers must not read a monetary field from the request beyond a display preference. Golden-case assertions in the Creative financial tests.

### C2. Represent money as `decimal(12,2)` plus an explicit currency; never as a PHP float in a boundary signature

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §10: floating-point arithmetic must never be introduced for monetary values; follow the existing money representation and rounding convention exactly.
`[CURRENT FACT]` `App\Domain\Payment\Support\Money` is a **float-based conversion helper, not a money value object** — it must not be treated as one, and it must not be extended by a Creative task.
`[OPEN DECISION]` Whether Creative introduces a proper `Money` value object, or the shared one is upgraded, is undecided. Do not decide it inside a Creative feature.

### C3. A project's agreed commercial terms are frozen at acceptance and never recalculated

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §10: financial history is immutable; fix forward with compensating records.
`[CURRENT FACT]` The pattern exists twice in this schema: `booking_pricing_breakdowns` (immutable per-screen-per-day snapshot) and `booking_screens` (gross, both shares **and both percentages stored together**, so the split is auditable without recomputation).
**Prevents:** a later pricing or FX change silently rewriting what a creator earned.

### C4. Store the resolved split, not only the totals

`[CURRENT FACT]` Precedent from `booking_screens`. `[RECOMMENDATION]` A Creative project stores the amount **and** the percentages that produced it **and** the FX rate if any.
**Prevents:** un-auditable splits and disputes that cannot be settled from the record.

### C5. Every Creative financial state change runs inside an explicit `DB::transaction`

`[ARCHITECTURE DOCUMENT]` Rule 4.
**Prevents:** partial writes such as an accepted proposal with no project, or an earning with no source project.

### C6. No network call inside a row lock

`[ARCHITECTURE DOCUMENT]` Rule 4, verbatim intent: external network operations must be isolated outside database row locks to prevent connection pool exhaustion and lock timeouts.
**Applies to:** the Razorpay funding call, any FX lookup performed during a financial write, and any notification that reaches a third party.
**Check:** manual review of every `lockForUpdate` site plus a grep for `lockForUpdate` in `app/Services/Creative` where a gateway call appears in the same method.

### C7. Money rows are written once. Corrections are new rows.

`[RECOMMENDATION]` A rejected delivery does not decrement an earning; it creates a reversing entry. A cancelled project does not mutate a payout; it creates a compensating record.
**Prevents:** unauditable history and reconciliation failures.

---

## Part D — Authorization

### D1. Authorization is enforced at the route boundary, and it stays there

`[ARCHITECTURE DOCUMENT]` Rule 12: the route/middleware check is the authoritative check.
**Prevents:** a Creative controller that "also" checks permissions itself and diverges from the middleware.

### D2. `RolePermissionChecker` is the single owner of the permission algorithm

`[ARCHITECTURE DOCUMENT]` Rule 12: exactly one owner, shared by every caller, never duplicated per caller, never owned by the transport layer.
**Prevents:** a second permission resolver inside Creative that grants slightly different access.
**Check:** grep for `role_permissions` or `Role::` outside the checker and the existing authorization classes.

### D3. Preserve the super-admin bypass asymmetry exactly

`[ARCHITECTURE DOCUMENT]` Rule 12 names this example explicitly. `[CURRENT FACT]` The bypass exists **only** in `PermissionMiddleware` (lines 27–30) and deliberately **not** in `RolePermissionChecker`. `RolePermissionChecker`'s own docblock states there is deliberately no bypass there.
**Prevents:** a well-meaning "consistency fix" that changes authorization behaviour. If a test fails because the guard is correct, fix the test or report it — never relax the guard.

### D4. Creative adds resource-level authorization (policies) without refactoring existing authorization

`[RECOMMENDATION]` `app/Policies/Creative/` is new capability answering "may this actor touch *this* project/file/earning". `[CURRENT FACT]` The application has 0 policies and 0 gates today; existing authorization is middleware plus ad-hoc ownership checks. Creative must not migrate existing routes to policies as part of a Creative task — that is a separate, authorised piece of work.
**Prevents:** a Creative initiative silently changing the authorization of 385 existing routes.

### D5. Ownership checks are never optional on a Creative resource

`[RECOMMENDATION]` Every Creative read and write of a brief, proposal, project, revision, delivery, review, earning or private file resolves the actor and verifies ownership or permission. A route parameter alone is never sufficient.
**Prevents:** insecure direct object reference, recorded as a P0/P1 class of risk in artifact 19.

### D6. A `creator` role is a role **row**, not a new authorization system

`[CURRENT FACT]` `RolePermissionChecker` resolves by role slug and resolves an unknown role to an **empty** permission list. `[CURRENT FACT]` Code already writes/queries role values (`partner`, `user`, `client`) that are not seeded, which silently yields zero permissions.
**Prevents:** a Creative role that exists in code but grants nothing, or a Creative-specific permission resolver.
**Check:** every Creative permission string must exist as a `role_permissions` row for the `creator` role, asserted in a test.

---

## Part E — Transactions, concurrency and idempotency

### E1. Follow the protected booking shape for any contested state change

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §11:

```text
check -> DB::transaction -> lockForUpdate() -> re-check inside the transaction -> mutate -> commit
```

**Applies to:** proposal acceptance, project creation, funding confirmation, delivery acceptance, review submission, earning approval, payout processing.
**Prevents:** two concurrent acceptances creating two projects, or double-payment.

### E2. Do not move a `lockForUpdate` out of a transaction, and do not casually reorder lock acquisition

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §11 — reordering changes deadlock behaviour.

### E3. Duplicate-request protection must exist for every money-affecting endpoint

`[RECOMMENDATION]` Prefer a database `unique` constraint over an application check wherever the invariant is expressible as a constraint (for example one accepted project per accepted proposal, one payout item per earning).
`[CURRENT FACT]` The platform has **no** idempotency keys, no idempotency table, and no request de-duplication today. That is a platform gap, not something Creative may assume exists.

### E4. Events are emitted after commit, never inside the transaction body

`[RECOMMENDATION]` `[CURRENT FACT]` Today's events are dispatched synchronously from within workflows; notification dispatch timing is a documented, preserved behaviour of the existing system.
**Prevents:** a notification for a project that was rolled back.
**Open:** whether Creative notifications are sent inline or queued is part of artifact 18; the rule here is only that dispatch must not precede commit.

---

## Part F — Reuse discipline

### F1. Do not duplicate shared Marketplace infrastructure

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` and the project's core principle: refactor the implementation, not the contract; and `ARCHITECTURE_RULES` Rule 7 (zero business logic duplication — wrap and delegate, never copy).
The full list of infrastructure that already exists and must be reused, with the "do not create" counterpart for each, is in artifact 10 under `tables_creative_must_not_duplicate`. In short: a second user system, a second authorization resolver, a second payment gateway abstraction, a second FX implementation, a second storage architecture, a second notification log, a second audit table, a second settings store, a second geography store, a second cache/queue/session store.

### F1.1. Do not add a settings key for a rule that has not been decided

`[RECOMMENDATION]` Creative configuration lives in the existing `settings` store as a `creative.*` group. Naming a key presupposes the rule it configures. `creative.revision_limit` presupposes a revision limit (OPEN_DECISION OD-SM-08); `creative.dispute_window_days` presupposes a dispute window (OD-SM-09/10); `creative.payout_minimum` presupposes a payout minimum (OD-SM-16). **None of those rules exists yet, so none of those keys may be created yet.**

**Prevents:** a fabricated default silently becoming the de facto business rule, because a key exists and something reads it.
**Also forbidden:** any settings key that determines a monetary outcome. That belongs in a versioned Creative table so historical earnings stay reproducible (rule C3/C4).

### F2. Wrap and delegate; do not copy business logic

`[ARCHITECTURE DOCUMENT]` Rule 7. When Creative needs a capability that exists, the correct first move is to call the existing contract or service behind a Creative-facing interface, verify equivalence with tests, and only then consider extraction.
**Prevents:** the pricing engine, the commission rules or the payment flow being reimplemented "for Creative", which is how two financial truths appear.

### F3. Do not modify Advertising Marketplace behaviour while implementing Creative

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §18, §19 and the phase migration order. Existing debt — 7 pinned `Domain -> Services` imports, 6 `Domain -> Models` files, 11 service-locator call sites, 33 repository-extraction candidates, 2 pre-existing test failures — is **controlled, documented debt with a scheduled order.** Resolving it out of order is a regression, not a courtesy.
**Prevents:** a 400-line "while I was here" diff that is unreviewable in the areas the phase plan is protecting.

### F4. Do not introduce a new architectural layer to avoid a decision

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §20. If ownership is unclear, do responsibility analysis, dependency analysis and consumer analysis, then either make a **documented** decision or record it as deferred.
**Prevents:** `Creative/Common/`, `Creative/Utils/`, `Creative/Managers/`, `Creative/SharedLogic/` — all explicitly forbidden by `AGENTS.md` §7.

### F5. Do not add a second creative boundary in this repository

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §1 and §9. Creative is a bounded context **inside** Project 01. The CMS (Project 03) is a separate Laravel application with a separate database. Creative must not create CMS controllers, models, migrations, tables or routes, and must not add a cross-database join or foreign key to a CMS database.
**Prevents:** the single most expensive boundary error available in this repository.

---

## Part G — Contracts and delivery discipline

### G1. Existing external contracts are frozen

`[ARCHITECTURE DOCUMENT]` Rule 5 and `AGENTS.md` §13. No change to any existing URI, method, request field, validation rule, response field, status code, error envelope, pagination semantic, authentication behaviour or authorization semantic. `tests/Feature/Golden/GoldenApiContractRegressionTest.php` must stay green, and route parity (385 today) may only **increase** by newly added Creative routes.
**Prevents:** a Creative change breaking an advertising client.

### G2. Existing migrations are never edited

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §14. Creative migrations are new, additive and independently reversible. Reference baseline: 112 migrations, 73 tables, 96 foreign-key definitions (94 ->constrained() + 2 legacy ->foreign()) today; any deviation is a schema change and must be reported as one.

### G3. No opportunistic deletion or renaming

`[ARCHITECTURE DOCUMENT]` Rule 13 and `AGENTS.md` §16. A class may be deleted only after **both** reference counting and framework-registration verification pass, because Laravel resolves console commands, scheduled tasks, listeners, relations, policies and providers by convention. This repository has already demonstrated the failure: a lexical scan flagged three **live** scheduled commands (`rewards:expire`, `bookings:expire-pending`, `currency:sync-rates`) as removable.

### G4. Required verification for any Creative code-movement or architecture task

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §17:

```text
tests/Unit/Architecture/ArchitectureRuleTest.php
php artisan route:list        route parity
composer dump-autoload        autoload + container resolution
php artisan test              full suite
./vendor/bin/pint --test      for the files the task touches
```

Pre-existing reference state that must be **reported**, never silently absorbed: **673 passed, 2 failed, 1 warning, 4 skipped, 5564 assertions** (failures: message-wording drift in `tests/Feature/BookingConcurrencyTest.php` and `tests/Feature/CampaignBookingPricingIntegrityTest.php`; warning: `MediaHelperTest`). `pint --test` fails repository-wide for pre-existing reasons.

### G5. Report what you did not fix

`[ARCHITECTURE DOCUMENT]` `AGENTS.md` §18 and §24. If you find a problem outside your task, record it as a deferred finding with a file reference. Do not expand scope. The final report must state: what changed, why, files changed, architecture/API/database/financial/security impact, the exact test commands run with exact counts, remaining risks, and deferred findings.

---

## Part H — Rules that cannot be written yet

`[OPEN DECISION]` The following cannot be settled from source or documentation and must not be decided by an implementing engineer:

| ID | Question | Rule that is blocked |
|---|---|---|
| OD-DB-08 | Can one user be advertiser **and** creator? | Whether the creator role lives in `users.role` or is derived from a `creators` row |
| OD-DB-01 | Public identifier strategy (ULID / UUID / prefixed reference) | The identifier type on every Creative route and every `public_id` column |
| OD-DB-02 | Is a brief bound to a creator service or open? | Whether `creative_briefs.creator_service_id` is nullable and whether a match table exists |
| OD-DB-03 | One proposal per brief or many? | The unique constraints that make acceptance race-free |
| OD-DB-04 | Is escrow / milestone funding in scope? | Whether funding needs tranches and a held balance |
| OD-DB-05 | When does an earning accrue; is there a dispute window? | The earning status vocabulary and whether a settlement period is needed |
| OD-DB-06/07 | Tax, third-party costs, multi-currency | The `AGREED TERMS` invariant formula and every `*_currency` column |
| OD-COMM | Commission basis, approval authority, payout cadence, payout provider | The earnings and payout state machines, and the percentage that must **not** be invented |
| OD-STORAGE | Are Creative files private by default; retention and consent | The visibility semantics of every `creative_files` row |
| OD-AUDIT | Can `activity_logs` express a Creative subject? | Whether a Creative audit table is needed |
| OD-JOBS | Is `app/Support/Jobs/Creative/` created? | Whether Creative jobs exist as jobs or as queued Mailables |

**Handling rule for all of them:** record the decision as deferred, state exactly what information is missing, proceed with everything that does not depend on it, and ask. Do not pick a default and move on.

---

## Compliance checklist before any Creative pull request

1. No `App\Http`, `App\Services` or `App\Models` import inside `app/Domain/Creative`.
2. No `Http::`, `Mail::`, `Storage::`, `curl_`, or a vendor SDK type in `app/Domain/Creative` or in a Creative application signature.
3. No second user system, authorization resolver, payment gateway, FX implementation, storage stack, notification log, audit table, settings store or queue.
4. Every money field server-derived; `decimal(12,2)`; currency explicit; float money nowhere.
5. Every money-affecting write inside `DB::transaction`; no network call inside a lock; every contested transition locks and re-checks.
6. Every Creative route carries authentication **and** `permission:creative.*`; every resource access has an ownership check.
7. Events dispatched after commit only.
8. No existing route, contract, migration, test outcome or financial behaviour changed; route parity increased only by new Creative routes.
9. `ArchitectureRuleTest`, `route:list`, `dump-autoload`, full `php artisan test` run, with exact counts reported.
10. Deferred findings listed, not fixed.
