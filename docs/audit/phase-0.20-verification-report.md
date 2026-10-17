# Phase 0.20 — Verification Report

**Captured:** 2026-09-26
**Verdict:** ✅ **PASS** — physical structure conformance achieved with controlled migration debt.
**Scope caveat:** this report verifies *structure and non-regression*. It does **not** certify that the codebase is architecturally clean, and it does not clear Phase 1.

---

## 1. Test suite

```
Tests:  2 failed, 1 warning, 4 skipped, 571 passed (5323 assertions)
Duration: ~56–66s
```

| | Baseline (pre-Phase-0.20) | Final | Δ |
|---|---|---|---|
| Passed | 571 | 571 | 0 |
| Failed | 2 | 2 | 0 |
| Warning | 1 | 1 | 0 |
| Skipped | 4 | 4 | 0 |
| Assertions | 5,323 | 5,323 | 0 |

**The 2 failures and 1 warning are pre-existing and identical to baseline:**

- `Tests\Feature\BookingConcurrencyTest > second booking rejected after first consumes last unit`
- `Tests\Feature\CampaignBookingPricingIntegrityTest > booking rejects quantity over fleet availability`
  — both are *message-wording* drift, not behavioural change.
- `tests/Unit/MediaHelperTest.php:23,28` — tautological assertion (compares a value to itself).

### Regression that occurred and was repaired

Mid-phase, Batch 2c produced **170 failures**. Root cause, confirmed empirically rather
than assumed: a moved class's own *unqualified* reference to a sibling left in the old
namespace.

```php
// app/Services/Campaign/LoyaltyService.php — no import statement ever existed
protected SettingsService $settings;
```

After the move PHP resolved this to the nonexistent `App\Services\Campaign\SettingsService`,
surfacing as a `BindingResolutionException` from `app/Models/Booking.php:46` reached via
`app/Services/BookingWorkflowService.php:58`.

`/tmp/p020/ns_resolve_check.py` validated all **1,109** short-name symbol references in
`app/` against the live autoloader. **3 real defects**, all now fixed by adding the
missing import:

| File | Import added |
|---|---|
| `Services/Campaign/BudgetRecommendationService.php` | `App\Services\CampaignPricingService` |
| `Services/Campaign/BudgetRecommendationService.php` | `App\Services\InventoryService` |
| `Services/Campaign/LoyaltyService.php` | `App\Services\SettingsService` |

The checker also surfaced 10 **false positives** — quoted words (`'Category'`, `'Partner'`,
`'PaymentController'`) used as `str_contains()` needles and CSV header labels, not class
references. These were deliberately left unchanged.

---

## 2. Public API contract — routes

| | Before | After |
|---|---|---|
| Routes | 385 | 385 |
| Method + URI + middleware fingerprint | `routes.before.json` | `routes.after.json` |

**Result: IDENTICAL** — compared on the `(method, uri, middleware[])` triple, so a silent
middleware change would have failed the check.

---

## 3. Database

| Metric | Value | Δ |
|---|---|---|
| Tables | 74 | 0 |
| Foreign keys | 94 | 0 |
| Total distinct indexes | 222 | 0 |
| Views | 0 | 0 |
| Migrations applied | 112 | 0 |
| Migration files on disk | 112 | 0 |
| Pending migrations | 0 | 0 |

No migration was created, altered or reverted. `mysqldump` is unavailable in this
environment, so the census is taken from `information_schema` plus exact `COUNT(*)`
per table rather than a logical dump comparison.

> Note: an earlier working note cited "74/796/252/94". Those row/index figures were
> inaccurate. The table and FK counts were correct; the authoritative row and index
> totals are **6,664 exact rows** and **222 distinct indexes**, as measured above.

---

## 4. Autoloading and container resolution

- `composer dump-autoload` re-run after every batch.
- All **64** relocated classes resolve through the autoloader.
- Container bindings verified live via `php artisan tinker`:
  - `App\Repositories\Asset\AssetRepositoryInterface` → `App\Repositories\Asset\AssetRepository` ✅
  - `App\Repositories\Booking\BookingRepositoryInterface` → `App\Repositories\Booking\BookingRepository` ✅
  - `App\Infrastructure\Storage\Contracts\StorageContractInterface` → `LaravelStorageAdapter` ✅
  - `App\Infrastructure\Notifications\Contracts\MailContractInterface` → `LaravelMailAdapter` ✅
- Old namespaces confirmed empty: `App\Events`, `App\Exceptions`, `App\Repositories\AssetRepositoryInterface`, `App\Application\Asset`.

---

## 5. Architecture rule test

```
Tests\Unit\Architecture\ArchitectureRuleTest — 14 passed (17 assertions)
```

The exact-pinned debt string for `LoyaltyService` was updated to
`App\Services\Campaign\LoyaltyService` to follow the class. **The pinned debt count is
unchanged** — the update tracks a move, it does not forgive a new violation.

---

## 6. Namespace / import integrity

| Check | Result |
|---|---|
| Stale references to any pre-migration FQCN | 0 |
| Dangling `App\` imports pointing at a non-existent class | 0 |
| Unqualified short-name references that would mis-resolve | 0 (11 flagged items all verified as string data) |
| Spurious imports created from comments/docblocks | 6 found and removed |

The 6 removed imports came from `PricingPlanRequest`, `DistributeBookingRevenueUseCase`,
`RefundPaymentUseCase`, `PartnerCommissionRepositoryInterface` and
`CampaignPricingService` (2). They existed only because the migrator's consumer scan
originally read comment and docblock text. The tool was patched to ignore those lines.

---

## 7. Test-file integrity

14 test files were touched, and **only** for namespace imports or exact-pinned
architecture strings. No test assertion, fixture, factory or test method name was changed.
The 2 pre-existing failures were left failing rather than edited — rewriting a test to
match current behaviour would have destroyed the evidence that the behaviour is unchanged.

---

## 8. Hard-stop checklist

Every stop condition in the phase definition was checked. None triggered.

| Stop condition | Result |
|---|---|
| Route / API drift | ✅ none — 385 identical |
| Authorization drift | ✅ none — middleware fingerprints identical, auth tests green |
| Financial drift | ✅ none — 0 P3 financial classes moved |
| Transaction / lock drift | ✅ none — `InventoryService` and `BookingWorkflowService` deliberately not moved |
| Schema drift | ✅ none — 74/94/222/112 unchanged |
| Runtime / binding failure | ✅ none — all bindings resolve |
| External integration drift | ✅ `IntegrationBoundaryTest` 23/72 |
| Scheduler / queue / event drift | ✅ 4 scheduled tasks unchanged, all 34 events resolve |

---

## 9. Known limitations of this verification

Stated plainly so the PASS is not over-read:

1. **Physical conformance is not architectural maturity.** Every target directory now
   exists, but 27 `Services` classes, 32 `Application` classes, 28 `Mail` classes and 33
   Eloquent-in-service candidates are still unmigrated. The directory tree is ready; the
   contents are not.
2. **6 `Domain` files still import `App\Models`** (9 import edges) and
   `ArchitectureRuleTest` does **not** catch this. The suite's green result therefore
   overstates domain purity. Logged as **P1**.
3. **17 `Application/Reporting` classes have no plan-assigned target.** The plan defines
   no Reporting capability. Rather than invent one, this is flagged **OPEN** for a plan
   clarification.
4. **No Git.** `core-backend` has no `.git`, so there is no commit history to diff
   against and no `git revert`. Rollback depends on the per-batch reports in
   `/tmp/p020/report*.json`, which record every `old_path`, `old_namespace` and
   `old_class`.
5. **Two baseline failures remain open.** They predate this phase and are not fixed here.
6. **Phase 1 remains BLOCKED** on `SEC-01`, `SEC-02`, `SEC-03` and `DEP-01`. Nothing in
   this phase touches those.
