# Phase 0.20 — Migration Order Plan (as executed)

**Captured:** 2026-09-26
**Authoritative source:** `Illuvision — Three Project Architecture Plan — Architecture baseline • 18 September 2026`

This is the order in which the physical migration was actually executed. Each step was
gated on a green regression before the next step began. The ordering rule was:

> risk ascending — pure leaf contracts and adapters first, orchestration last, and
> nothing that touches money, transactions or concurrency until every lower-risk move
> was proven safe.

---

## Step 0 — Baseline capture (no writes)

| Item | Value |
|---|---|
| Production classes | 336 |
| Directories under `app/` | 69 |
| Routes | 385 |
| Test suite | 571 passed, 2 failed, 1 warning, 4 skipped, 5,323 assertions |
| Schema | 74 tables, 94 FKs, 112 migrations, 0 views |
| Route snapshot | `/tmp/p020/routes.before.json` |
| Class inventory | `/tmp/p020/inventory.json` |

**Rationale:** a namespace migration without a recorded "before" cannot be proven
non-breaking. Every later step is diffed against this snapshot.

---

## Step 1 — Create the full physical tree (no class moves)

All 40 plan-mandated directories were created in one pass, including the ones with no
implementation: `Domain` × 12, `Domain/Creative` × 9, `Services` × 6, `Repositories` × 6,
`Infrastructure` × 6, `Support` × 3, `Http` × 3.

**Rationale:** the plan requires the *complete* structure to exist even where empty.
Creating the tree first means the final state is structural rather than a by-product of
however many classes happened to move. Empty directories are an accepted outcome; fake
placeholder classes are not.

---

## Step 2 — Batch 1: Infrastructure adapters (4 classes, risk: lowest)

| From | To |
|---|---|
| `App\Infrastructure\Notification\Contracts\MailContractInterface` | `App\Infrastructure\Notifications\Contracts\MailContractInterface` |
| `App\Infrastructure\Notification\LaravelMailAdapter` | `App\Infrastructure\Notifications\LaravelMailAdapter` |
| `App\Infrastructure\Media\Contracts\StorageContractInterface` | `App\Infrastructure\Storage\Contracts\StorageContractInterface` |
| `App\Infrastructure\Media\LaravelStorageAdapter` | `App\Infrastructure\Storage\LaravelStorageAdapter` |

14 consumers updated. Gate: `IntegrationBoundaryTest` 23/72, routes identical, both
container bindings resolved.

**Rationale:** leaf contracts with a single implementation and no business logic. A
broken move here fails immediately and loudly at container build, not at runtime.

---

## Step 3 — Batch 2a: Events (34 classes, risk: low)

33 `App\Events\Notifications\*` → `App\Support\Events\Notifications\*`, plus
`App\Events\AssetCreated` → `App\Domain\Asset\Events\AssetCreated`.
90 consumers updated, 3 same-namespace imports inserted. Empty `app/Events/` removed.

**Rationale:** event classes are pure immutable value carriers. `AssetCreated` was moved
into `Domain` because it is a domain fact, not a technical event. Gate: 14/43 event and
observer tests.

---

## Step 4 — Batch 2b: Exceptions and enums (6 classes, risk: low)

4 exceptions relocated to their owning domain, 2 enums relocated, `AssetType` retained
flat. 21 consumers updated.

**Rationale:** exceptions and enums are the smallest possible move units. `AssetType` was
deliberately **not** moved: it is shared by Asset, Media, Availability and Campaign, and
duplicating it into four domains would create exactly the ambiguous dual ownership the
plan forbids.

---

## Step 5 — Batch 2c: Services (14 classes, risk: medium) ⚠

14 orchestration services moved into `Campaign`, `Booking`, `Payment` and `Settlement`.
59 consumers updated, 13 candidate same-namespace imports detected.

**This step initially broke the suite: 170 failures.** See "Failure analysis" below.

---

## Step 6 — Batch 2d: Repositories + `Application/` resolution (6 classes, risk: low)

`AssetRepositoryInterface`/`AssetRepository` → `Repositories/Asset/`;
`BookingRepositoryInterface`/`BookingRepository` → `Repositories/Booking/`;
`AssetDiscoveryFilterService` and `CoverageService` → `Services/Campaign/`.

12 consumers updated, 2 same-namespace imports inserted. Both container bindings verified
via `php artisan tinker`. Final suite: **571 passed**.

---

## Failure analysis — the ADR-18-04 trap, third form

Moving a class out of `App\Services` broke 170 tests. The cause was **not** a missed
import. It was this:

```php
// app/Services/Campaign/LoyaltyService.php  (namespace App\Services\Campaign)
protected SettingsService $settings;   // no import statement exists
```

`SettingsService` had never needed an import, because `LoyaltyService` used to live
alongside it in `App\Services`. After the move, PHP resolves the unqualified name against
the **new** namespace and looks for `App\Services\Campaign\SettingsService` — which does
not exist.

```
BindingResolutionException: Target class [App\Services\Campaign\SettingsService] does not exist.
  at app/Models/Booking.php:46
  at app/Services/BookingWorkflowService.php:58
```

**Three real defects** (found by `/tmp/p020/ns_resolve_check.py`, which validates all
1,109 short-name symbol references in `app/` against the live autoloader):

| File | Missing import |
|---|---|
| `Services/Campaign/BudgetRecommendationService.php` | `App\Services\CampaignPricingService` |
| `Services/Campaign/BudgetRecommendationService.php` | `App\Services\InventoryService` |
| `Services/Campaign/LoyaltyService.php` | `App\Services\SettingsService` |

The checker also reported 10 **false positives** — quoted words such as `'Category'`,
`'Partner'`, `'PaymentController'` inside `str_contains()` calls and CSV header arrays.
Those are string data, not class references, and were correctly left alone.

**The lesson, now encoded in the tooling:** a namespace move is only safe if you check
all four of

1. explicit imports of the moved class elsewhere,
2. same-namespace consumers of the moved class,
3. **the moved class's own unqualified references to siblings left behind** ← this one,
4. string literals and leading-`\` FQCNs.

Only (1) is caught by a naive `grep` for the old namespace. Forms (2) and (3) are silent
until runtime.

---

## What was deliberately NOT migrated, and why

| Deferred | Count | Reason |
|---|---|---|
| `Services` P3 financial | 10 | money-handling; relocating in the same phase that restructured the tree concentrates risk |
| `Services` P4 transaction/concurrency | 5 | `InventoryService` owns `SELECT … FOR UPDATE`; highest-risk class in the codebase |
| `Application/Booking` use cases | 5 | P4 transaction + concurrency |
| `Application/Financial` + `Payment` + `Pricing` | 11 | P3 financial |
| `Application/Reporting` | 17 | the plan defines **no** Reporting capability; inventing one exceeds Phase 0.20's mandate — flagged **OPEN** |
| `Mail` (28 Mailable classes) | 28 | not a plan directory; documented in the legacy register |
| Repository extraction | 33 | requires contract extraction first (plan §12, 7 steps), not a rename |

The governing principle: **a target directory existing is not a reason to move a class.**
