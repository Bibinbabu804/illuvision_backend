# Phases 0.17–0.18 — Architecture Decisions

- **Date:** 2026-09-26
- **Project:** `illuvision/core-backend`
- **Decisions taken during legacy retirement and modular migration**

## ADR-17-01 — Delete first, reorganise second

The 16 dead classes were removed in a single batch **before** any file was moved.
Partial deletion and relocation interleaved would have made it impossible to attribute
a regression to one cause.

**Consequence:** a clean behavioural baseline (571 passed) existed before the first move.

---

## ADR-17-02 — Runtime registration overrides static reference counting

The first detector pass flagged `ExpirePendingBookings`, `ExpireRewardPoints` and
`SyncExchangeRates` as dead because their class names do not appear in their Artisan
signatures (`bookings:expire-pending`, `rewards:expire`, `currency:sync-rates`).

**Decision:** a class is only dead if it is absent from *every* runtime registration
surface — routes, scheduler, Artisan, container, Gate, Eloquent conventions, config,
database-stored class names, events, jobs and tests.

**Consequence:** three live scheduled jobs were preserved. This reproduces the exact
failure mode Phase 0.13 warned about and is now recorded in
`phase-0.17-dead-code-verification.json` so future inventories do not repeat it.

---

## ADR-17-03 — Gate policy convention must assert the model exists

`Gate::getPolicyFor()` returned `AssetPolicy` for the class string `App\Models\Asset`
— **a class that does not exist**. The convention lookup only checks whether the
conventional policy *name* resolves.

**Decision:** a policy is live only if a real model resolves to it. A convention match
alone is a phantom.

**Consequence:** `AssetPolicy` and `PartnerBookingPolicy` were deleted, reversing the
Phase 0.13 decision that retained them.

---

## ADR-17-04 — Unused models over live tables are retained

`CoverageCity`, `MobileAssetCoverageCity`, `MobileAssetCoverageState` and
`PricingDiscountRule` have zero code references, but map tables that are live
(3, 28 and 8 rows respectively, all read through the query builder) or that are
FK-constrained into the live pricing schema.

**Decision:** retain. Dead *code* is not the same as dead *data ownership*.

**Consequence:** 4 candidates left alive out of 21. Recorded with rationale so a
future phase can revisit them once the Asset/Coverage boundary is settled.

---

## ADR-17-05 — Rule 7 forbids copying logic, not deleting an unreferenced class

Phase 0.13 deferred `PeakPricingCalculator`, `WeekendPricingCalculator` and
`CampaignDiscountCalculator` on the grounds that Rule 7 forbids their deletion. Rule 7
actually forbids **copying** large blocks of business logic elsewhere.

**Decision:** the deferral was a mis-citation. After identifying the canonical owner of
each behaviour (`PricingPeakRule`, `PricingWeekendRule`, `CampaignPricingEngine`) and
proving zero references, all three were deleted. The canonical owner is now explicit.

**Consequence:** the `Services/Pricing` sub-namespace now contains only live,
Eloquent-aware classes.

---

## ADR-17-06 — A wrapper is not removed because its target exists

`LegacyPricingEngineAdapter` is a pure pass-through, but it is the live implementation
behind `/api/v2/pricing/calculate`. `CampaignPricingService` was *assumed* to be a pure
wrapper but on inspection retains genuine reconciliation logic, so it is not one.

**Decision:** classify, then decide. Remove a wrapper only after every consumer has
migrated *and* removal does not change an external contract.

**Consequence:** 0 wrappers removed.

---

## ADR-18-01 — Migrate only contract-dependent classes first

Three classes were migrated because each depends **only** on contracts:
`AssetDiscoveryFilterService` and `CoverageService` (both only on
`AssetRepositoryInterface`) and `NotificationEventSubscriber` (only on the framework
dispatcher). No Eloquent models, no `Domain` back-references, no `Http`, no locators.

**Decision:** ownership is clarified by relocation only when the class's dependencies
already express its role.

**Consequence:** `Application/Asset` became the first genuinely owned module.

---

## ADR-18-02 — Eloquent-shaped services must not be moved before a port exists

Moving an Eloquent-heavy service into `Application/` without first extracting a
persistence port would place `Models` inside `Application` and make ownership *less*
clear, which Rule 26 explicitly prohibits.

**Decision:** for Eloquent-shaped services the order is **extract port → then move**.
That is plan order 7.

**Consequence:** 45 services remain in place, sequenced with verification gates.

---

## ADR-18-03 — Financial paths are not migration candidates in this phase

The brief lists pricing, availability, booking, payment, refund, settlement, commission
and revenue split as behaviour-change hard stops. 18 of the 45 remaining services fall
into those categories, including the availability trio whose separation is deliberate —
`BookingCapacityService` is a non-locking *check* while `InventoryService` holds the
`lockForUpdate` reservation inside the booking transaction.

**Decision:** do not move them. Consolidating the availability pair or the booking or
payment pairs would change lock or financial semantics.

**Consequence:** the migration is explicitly partial, with the remainder documented as
blocked pending a behaviour decision rather than left implicit.

---

## ADR-18-04 — Verify namespace moves by resolving consumers, not by grepping imports

Moving `CoverageService` and `AssetDiscoveryFilterService` out of `App\Services` broke
`App\Services\AssetService`, which type-hinted both from its own namespace and therefore
had **no import statement to rewrite**. The failure surfaced as:

```
BindingResolutionException: Target class [App\Services\CoverageService] does not exist.
```

Both a stale-FQCN grep and resolving each migrated class in isolation had already
passed.

**Decision:** a namespace move is verified only when every consumer resolves through
the container. Same-namespace consumers are invisible to an import grep.

**Consequence:** the two missing `use` statements were added to `AssetService.php`, and
the check is now part of the standard migration gate.

---

## ADR-18-05 — Empty directories are not created to satisfy the target tree

Only 2 of 26 probed target directories exist. The empty `app/Core` scaffolding from an
earlier phase was **removed** rather than populated.

**Decision:** a target diagram is a description of intent, not a checklist to satisfy
with empty folders. Section 3 of the brief forbids it.

**Consequence:** conformance is reported honestly as partial.

---

## ADR-18-06 — Record the unenforced `Domain → Models` rule instead of silently ignoring it

`App\Models` is absent from the `Domain` forbidden-import list in
`ArchitectureRuleTest`, yet 5 Domain files import it. The Phase 0.15 debt register did
not capture this.

**Decision:** do not weaken the test and do not attempt a large contract rewrite
mid-migration. Document the gap and recommend adding the check with an explicit 5-class
allowlist so the debt becomes visible and cannot grow, then remove it class by class
via DTO-returning repository contracts.

**Consequence:** new, previously unrecorded architecture debt is now tracked.

---

## ADR-18-07 — Infrastructure and Http needed no migration

A sweep of all 48 services, the models and the controllers found **zero** external
HTTP calls outside `app/Infrastructure`, where every integration already sits behind a
contract. All 81 controllers are already correctly layered.

**Decision:** leave both layers alone. Cosmetic renaming of `Payments`/`Notification`
to match the target diagram would be churn with breakage risk and no ownership gain.

**Consequence:** the earlier infrastructure work already delivered what Phase 0.18 was
asked to produce there.
