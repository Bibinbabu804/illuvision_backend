# Phases 0.15-0.16 - Architecture Decisions

Generated 2026-09-26

Five decisions were taken. Each one is a decision **not** to act, and each is
recorded with the evidence that justified restraint.

---

## AD-01: Add no production code changes in Phase 0.15

**Decision.** Phase 0.15 changed exactly one file, and it is a test.

**Why.** All 18 service locators and the single Domain->Services violation fall
into Category B (needs characterisation) or Category C (behaviour-sensitive).
The 8 locators in `app/Models/Booking.php` resolve services from inside Eloquent
events, so loyalty and referral side effects execute inside whatever transaction
the caller opened. Touching them changes financial and loyalty outcomes. The
brief forbids that as a side effect of cleanup.

**Alternative rejected.** "Fix the easy ones now" - but the 10 locators outside
Models are cheap to convert only in isolation, and converting them without the
Models ones would leave the actual risk untouched while still moving money
through the same code paths. Partial cleanup of a transaction-timing hazard is
worse than none, because it makes the hazard harder to see.

**Result.** `tests/Unit/Architecture/ArchitectureRuleTest.php` (new, 14 cases).

---

## AD-02: Pin known debt exactly instead of deleting it or ignoring it

**Decision.** Each known violation is asserted as an exact expected set, so the
suite fails when debt is added **and** when debt is silently removed.

**Why.** Asserting "no violations" would be red forever, so it gets disabled.
Asserting nothing lets debt grow invisibly. Pinning makes the register
self-verifying: removing a violation forces a deliberate commit that also
updates `phase-0.14-architecture-debt-register.json`.

---

## AD-03: Record the three P0 authorization defects rather than fix them

**Decision.** SEC-01, SEC-02 and SEC-03 are documented, triaged P0, and left in
code.

**Why.** This is the hardest call in the phase. SEC-01 is a textbook
privilege-escalation: any authenticated user can set any booking's status and
price. The obvious instinct is to fix it immediately.

Every available fix changes something the brief protects. Adding permission
middleware changes an authorization outcome. Restricting the accepted fields
changes the API contract. Narrowing `status` to a `Rule::in` list changes
validation behaviour. Each is a genuine improvement and each is a contract
change that the brief places behind explicit approval.

So they are recorded with full exploit detail, a proven reproduction, a
recommended remediation order, and a P0 gate failure. The gate decision is
`PHASE 1 BLOCKED` so the exposure cannot be mistaken for accepted debt.

**Also verified they are pre-existing.** All three were compared against
`docs/audit/routes-baseline.json` and appear with identical middleware. The
audit found them; it did not create them.

---

## AD-04: Report no confirmed N+1 despite 73 candidates

**Decision.** Record 73 heuristic N+1 candidates, 0 confirmed.

**Why.** A lexical proximity scan flags `Model::query()` inside a loop. Every
measured relationship-bearing path was already eager-loaded - `booking_list`
with `screens` runs 2 queries, not 51. Reporting 73 vulnerabilities, or quietly
fixing 73 eager-loads, would both be false. Neither was done.

---

## AD-05: Ship no performance optimisation

**Decision.** Zero index, cache, pagination or eager-load changes.

**Why.** The only measurements available are from a small testing dataset.
Query counts are a valid structural signal; the latencies are not production
SLOs. Every plausible optimisation changes behaviour or the schema, and none was
justified by a measured regression. The financially critical **write** paths
were not benchmarked at all, which is recorded as a readiness gap rather than
smoothed over.
