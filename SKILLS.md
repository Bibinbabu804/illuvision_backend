# SKILLS.md — Illuvision Core Backend

**Companion to `AGENTS.md`.** This document describes the engineering skills and preferred implementation patterns for work in this repository. It is guidance, not policy: where this file and `AGENTS.md` disagree, `AGENTS.md` wins.

**Read `AGENTS.md` first.** This file assumes the architecture, boundaries and safety rules declared there.

Sections:

1. Laravel Architecture
2. Domain-Driven Modular Design
3. Persistence Architecture
4. API Design
5. Financial Engineering
6. Transaction and Concurrency Engineering
7. Security Engineering
8. Code Migration
9. Refactoring
10. Testing
11. Static Analysis and Search
12. Git Skills
13. Database Skills
14. Performance Skills
15. External Integration Skills
16. Documentation Skills
17. Agent Skill Selection
18. Final Rule

---

## 1. Laravel Architecture

Proficiency expected in:

- service container and constructor dependency injection;
- service providers (`app/Providers`) and explicit vs contextual binding;
- middleware and route middleware groups;
- policies and Gates;
- Form Requests (`app/Http/Requests`);
- API Resources (`app/Http/Resources`);
- queued jobs and `ShouldQueue` semantics;
- events and listeners;
- scheduler registration (`routes/console.php`, `app/Console/Commands`);
- database transactions;
- Eloquent and query builder;
- repository and application-service patterns;
- domain services vs application services.

Prefer explicit dependency injection. Avoid service locators inside business logic:

```php
// Preferred: constructor injection of a contract
public function __construct(private PaymentGatewayContract $gateway) {}

// Avoid inside business logic
$service = app(PaymentService::class);
$service = resolve(PaymentService::class);
$service = new PaymentService();
```

11 such call sites are recorded in `docs/audit/phase-0.20-service-locator-usage.json`. Do not add new ones; do not opportunistically remove the existing ones.

---

## 2. Domain-Driven Modular Design

Understand:

```text
bounded context | domain ownership | application orchestration | domain rules
repository contracts | infrastructure adapters | cross-domain dependencies
```

Before creating a class, answer:

```text
Which business capability owns this?
Which layer owns this responsibility?
Who is allowed to depend on it?
```

The capability list is fixed (`AGENTS.md` §7). A class with no clear owner is a decision to escalate, not a reason to create a new directory.

---

## 3. Persistence Architecture

Preferred shape:

```text
Domain
  -> Repository Contract (interface)
    -> Infrastructure/Persistence implementation
      -> Eloquent Model
```

Do not move Eloquent query building into `Domain`. Do not introduce a repository for a simple Active Record read/write (Rule 6) — the repository abstraction must buy persistence isolation, complex-query encapsulation, or testability.

When extracting a repository:

1. identify the query responsibility that is actually persistence;
2. define the interface in terms of domain language, not query fragments;
3. implement the persistence adapter;
4. migrate consumers one at a time;
5. test;
6. remove the old path only after verification.

`app/Repositories/Asset` and `app/Repositories/Booking` are the reference examples. The 33 recorded extraction candidates in `docs/audit/phase-0.20-repository-extraction-candidates.json` are a backlog, not a current assignment.

---

## 4. API Design

Skills: REST conventions, Laravel routing, Form Requests, API Resources, pagination, validation, HTTP status codes, error envelopes, authentication (Sanctum tokens), authorization, idempotency.

Contract preservation is mandatory (`AGENTS.md` §3, §13). The existing response shapes, status codes and error envelopes are pinned by `tests/Feature/Golden/GoldenApiContractRegressionTest.php` and the JSON baselines in `docs/audit/`. Route count parity (385) is the minimum bar for any routing change.

---

## 5. Financial Engineering

Comfort required with:

- decimal arithmetic and the repository's existing money representation;
- rounding: scale, mode, direction (never change it incidentally);
- currency conversion and exchange-rate application;
- payment state machines and idempotency keys;
- payment gateway adapters (Razorpay lives in `app/Infrastructure/Payments`);
- refunds and refund allocation;
- partner commission and revenue split resolution;
- settlement periods and settlement amounts;
- immutable financial snapshots and history.

Never introduce floating-point arithmetic for money. Fix financial defects forward with compensating records; never rewrite stored financial facts. Run the financial regression suites in `AGENTS.md` §10 for any financial change.

---

## 6. Transaction and Concurrency Engineering

Understand:

```text
DB::transaction | SELECT ... FOR UPDATE | lockForUpdate() | lock ordering
deadlocks | race conditions | optimistic concurrency | idempotency | isolation levels
```

For booking and inventory code, the protected sequence is:

```text
check
  -> DB::transaction
    -> lockForUpdate()
      -> re-check availability inside the lock
        -> mutate
          -> commit
```

Never hold a row lock across a network call (Rule 4). When changing locking, reason explicitly about lock acquisition order and deadlock risk, and exercise the concurrent path — not just the happy path.

---

## 7. Security Engineering

Understand:

- authentication vs authorization separation;
- RBAC and the single permission algorithm owner (`app/Support/Authorization/RolePermissionChecker`);
- permission middleware and route-boundary enforcement (Rule 12);
- privilege escalation, IDOR, mass assignment;
- CSRF where relevant;
- webhook signature verification;
- secret management and production debug/environment settings (`tests/Feature/Security/Dep01DebugAndEnvironmentTest.php`).

Always test both the allowed and the denied path, including negative and privilege-escalation cases. A test that only proves the happy path authorised is not evidence of an authorization decision.

---

## 8. Code Migration

Preferred method:

```text
inventory
  -> classify responsibility
    -> dependency graph
      -> move
        -> namespace update
          -> consumer update (explicitly, file by file)
            -> container resolution check
              -> route parity check
                -> tests
```

Never perform blind bulk moves. Short-name resolution breaks when same-short-name classes become siblings in different directories, which is exactly why explicit import updates are mandatory. The Phase 0.20 migration recorded 0 same-namespace imports inserted and 198 consumers updated as proof of this discipline.

---

## 9. Refactoring

Preferred principle: **small, reversible, verified changes**.

Avoid a large rewrite when incremental extraction is possible. Never combine, in a single task:

```text
architecture refactor
  + business rule change
  + database redesign
```

The strangler pattern applies (Rule 7): wrap and delegate to the legacy implementation, verify equivalence against the golden suites, then extract deeper. Do not copy business logic into the new path.

---

## 10. Testing

**Unit tests** — domain rules, services, adapters, value objects (`tests/Unit/Domain`, `tests/Unit/Infrastructure`).

**Feature tests** — APIs, authorization, workflows (`tests/Feature`).

**Integration tests** — database behaviour, repositories, external adapters (`tests/Feature/Integrations`).

**Regression tests** — pinned existing behaviour: `tests/Feature/Golden/` (API contract, financial, pricing, payment, booking, availability, concurrency) and `tests/Feature/FinancialRegressionAndHardeningTest.php`.

**Architecture tests** — dependency direction, forbidden imports, namespace rules, module boundaries (`tests/Unit/Architecture/ArchitectureRuleTest.php`).

**Security tests** — `tests/Feature/Security/`, `tests/Feature/AuthorizationHardeningTest.php`.

For a behaviour-preserving refactor, characterization tests come first, then the refactor, then re-run. For a security fix, the characterization test must be written before the fix and pinned in the report.

---

## 11. Static Analysis and Search

Use multiple independent verification techniques:

```text
grep / rg | PHP token_get_all | PHP reflection | composer dump-autoload
container resolution | php artisan route:list | architecture tests | test suite
```

Do not rely on a single technique. In particular, `grep` cannot prove runtime resolution safety: Laravel resolves console commands, scheduled tasks, listeners, relations, policies and providers by convention, not by import. `ArchitectureRuleTest` uses the PHP token stream rather than regex precisely so that docblocks, comments and similarly-named helpers do not produce false positives.

---

## 12. Git Skills

Expected fluency with:

```text
git status | git diff | git log | git branch | git add | git restore | git stash
```

Rules:

- Never run `git reset --hard`, `git clean -fd`, or a history rewrite on a shared branch without explicit authorisation.
- Stage only the intended files; never stage secrets or `.env`.
- Commit only when explicitly asked.
- Review `git diff` line by line before committing; a diff larger than the task requires is a finding to report, not a quality signal.

---

## 13. Database Skills

Understand migrations, indexes, foreign keys, transactions, query performance, Eloquent relationships, locking, and MySQL 8 behaviour.

Before any schema change:

```text
inspect
  -> document the delta
    -> obtain explicit task scope
      -> migrate
        -> verify (table/FK/index counts, regression suite)
```

Reference baseline: 74 tables, 94 foreign keys, 112 migrations, 0 views.

---

## 14. Performance Skills

Know how to find and measure:

- N+1 queries and missing eager loading;
- query counts per request;
- index usage;
- caching and Redis usage;
- queue workload and job latency;
- external API latency and timeout budgets.

Measure before and after. Do not optimise by changing business behaviour, and do not trade away transaction or lock semantics for throughput. `docs/audit/phase-0.16-performance-baseline.json` and `phase-0.15-0.16-performance-readiness-map.json` hold the current baseline.

---

## 15. External Integration Skills

Use the adapter pattern:

```text
Business / Application
        -> Interface (contract)
          -> Infrastructure Adapter
            -> External API / SDK
```

Never instantiate SDK clients inside domain or business logic (Rules 1 and 3). Existing adapter areas in this repository:

```text
app/Infrastructure/Payments        (Razorpay)
app/Infrastructure/Auth
app/Infrastructure/ExchangeRates
app/Infrastructure/Storage          (storage + image processing)
app/Infrastructure/Notifications
```

Reuse these established boundaries instead of adding a parallel integration path. Tests must not perform real network calls — fake at the contract boundary.

---

## 16. Documentation Skills

Architectural changes must be recorded, not left implicit. Update the relevant artifacts in `docs/audit/`:

```text
architecture diff        (what moved / what changed)
migration ledger         (file movements, namespace changes)
architecture decisions   (the decision and its rationale)
production changes       (operational impact)
phase report / final report
```

Read every document you write back and verify each factual claim against the repository. A stale audit artifact is worse than none, because the next agent will treat it as authority.

---

## 17. Agent Skill Selection

Before starting a task, choose the skills that actually apply.

```text
Booking task
  -> Laravel | Domain design | Transactions | Concurrency | Testing | Authorization

Payment / settlement task
  -> Laravel | Financial engineering | Payment gateway adapters
     | Transactions | Idempotency | Testing

Pricing / commission task
  -> Laravel | Financial engineering | Determinism | Testing | Regression

Architecture migration
  -> DDD | Dependency analysis | Persistence | Container resolution
     | Static analysis | Regression testing

Reporting / analytics task
  -> Laravel | Query-layer design | Read-model performance | Testing

Security task
  -> Authentication | Authorization | RBAC | API security | Negative testing

External integration task
  -> Adapter pattern | Contracts | Idempotency | Failure handling | Testing

Documentation-only task
  -> Documentation skills | Source verification | Conflict reporting
```

Do not apply unrelated patterns simply because they are available. Over-application is itself a defect.

---

## 18. Final Rule

```text
The strongest implementation is not the one with the most abstractions.

The strongest implementation is the one that:

- has clear ownership;
- preserves external contracts;
- isolates infrastructure;
- protects financial truth;
- protects authorization boundaries;
- preserves transaction semantics;
- is testable;
- is observable;
- is deployable;
- and can be changed incrementally without losing behavioral control.
```
