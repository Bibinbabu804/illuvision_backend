# Phases 0.15-0.16 - Final Report

Generated 2026-09-26

## Decision: **PHASE 1 BLOCKED**

Not "ready with controlled debt". The exit gate failed 3 of 9 criteria, and all
three failures are the same class of problem: **unauthenticated or unauthorized
access to financial data and financial writes.**

Controlled debt is the right label for known, bounded, accepted risk. It is the
wrong label for four trivially exploitable findings.

---

## The four P0 blockers

### SEC-01 - `PATCH /api/bookings/{booking}` has no permission check
Proven at runtime, not inferred. A user whose
`RolePermissionChecker::userHasPermission($u, 'bookings.edit')` is `false` sent
`PATCH /api/bookings/{{id}} {{"status":"approved","price":1}}` and received
**HTTP 200 "Booking updated successfully"**. The row then held
`status='approved'`, `price=1.00`.

The mechanism is a verb-scope gap: `Route::apiResource('bookings')` registers
**both** `PUT` and `PATCH`; the later permission-gated `Route::put()` overrides
only `PUT` in Laravel's per-method lookup table, so `PATCH` keeps the apiResource
route whose middleware is just `['api','auth:sanctum']`. It is amplified because
`status` is validated as an unconstrained `sometimes|string` and both `status`
and `price` are `$fillable`, written straight through by
`RescheduleBookingUseCase:135`.

*Any authenticated user can set any booking's status and price.*

### SEC-02 - 19 `/api/v2/*` routes are fully anonymous
Middleware is `['api']` with no `Authenticate` entry. Anonymous callers can read
**all payments, the ledger, all settlements and the finance summary**, and can
**write settlement batches, create and transition bookings, and create, update
and delete assets**.

### SEC-03 - role and partner administration is authenticated but unpermissioned
`Authenticate:sanctum` is present; `PermissionMiddleware` appears **zero**
times across 7 `/api/roles*` and 17 `/api/partners*` routes. So
`PUT /api/roles/{{role}}/permissions` lets any authenticated user grant
themselves any permission, and `DELETE /api/partners/{{partner}}` and
`POST /api/partners/{{partner}}/approve` are reachable by any account.

### DEP-01 - `APP_DEBUG` is enabled
`php artisan about` reports `Debug Mode: ENABLED`. Combined with SEC-02 this
exposes stack traces, file paths and query SQL to **anonymous** callers.

### All four are pre-existing
Each was compared against `docs/audit/routes-baseline.json`, the
pre-remediation snapshot, and appears there with **identical middleware**.
Phases 0.5-0.16 neither introduced nor worsened them. This audit found them.

### Why they were not fixed
Every available fix changes something the brief protects: adding middleware
changes an authorization outcome; restricting fields changes the API contract.
They are recorded with exploit detail, a proven reproduction and a recommended
remediation order, and they fail the exit gate.

---

## What Phase 0.15 actually delivered

One file: `tests/Unit/Architecture/ArchitectureRuleTest.php` - 14 cases, 17
assertions, 0.29s.

That is thin by design, and the reasoning is in AD-01. Architecture conformance
is still 10/13 and still 18 locators, but three prose claims in
`ARCHITECTURE_RULES.md` are now executable, and known debt is pinned exactly so
it can neither grow nor vanish unnoticed.

Worth recording: the detector had three real bugs, all of which would have made
the audit quietly worthless - a previous-token lookup that scanned *forwards*
(defeating every `::`/`->`/`function` guard), PHP 8 `T_NAME_QUALIFIED` array
tokens concatenated as strings, and per-lookup re-tokenisation that made the
suite take 13.69s instead of 0.29s.

---

## What Phase 0.16 verified as sound

- **Pricing**: campaign booking ignores client-supplied money and stores the
  authoritative engine values.
- **Payments**: the order amount is server-derived and the verify request has no
  amount field at all.
- **No SQL injection**: no request input reaches `DB::raw` or concatenated SQL.
- **No open mass assignment**.
- **Concurrency**: inventory locking, transaction ownership and
  `DB::transaction(attempts: 5)` retry are in place; the golden concurrency
  suites pass.
- **No N+1**: 73 heuristic candidates, 0 confirmed; eager loading is effective.

## Honest gaps

- The financially critical **write** paths were never benchmarked.
- The 2 failing tests are message-wording drift (`"no longer available"` vs the
  current `EligibilityResult` message), not behaviour changes.
- `Storage` path traversal (STOR-01) is flagged but **not verified**.
- No error tracking, no structured logging, no health check.
- No version control and no backup/restore procedure, so nothing here is
  revertable.

---

## To reach `PHASE 1 READY WITH CONTROLLED DEBT`

1. `SEC-03` - gate role and partner administration. Smallest change, largest
   risk reduction: it is privilege escalation.
2. `SEC-01` - gate the `PATCH` verb or narrow the writable fields.
3. `SEC-02` - decide per route which `/api/v2` endpoints are intentionally
   public. `coverage`, `availability` and `recommendations` plausibly are;
   `finance/*` and the booking writes are not.
4. `DEP-01` - `APP_DEBUG=false`.
5. Initialise version control; document backup, restore and rollback.
6. Re-run the gate.

Items 1, 2 and 4 are each a few lines. They were not applied because each one
changes an authorization outcome or a contract, and that decision is not the
auditor's to make unilaterally.

## Artifacts

33 documents: 10 x `phase-0.15-*`, 16 x `phase-0.16-*`, 7 x `phase-0.15-0.16-*`.
