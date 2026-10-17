# Phase 0.15 - Architecture Diff

Generated 2026-09-26

## Principle
Phase 0.15 is an **audit and enforcement** phase. It deliberately makes **no
behavioural production change**, because every remaining violation sits in
Category B (characterisation required) or Category C (behaviour-sensitive) and
the brief forbids changing financial, authorization, transaction or event
outcomes as a side effect of cleanup.

## Production changes
None.

## Test changes (the deliverable)
| File | Change |
|---|---|
| `tests/Unit/Architecture/ArchitectureRuleTest.php` | **new**, 14 cases, 17 assertions, 0.29s |

The suite converts three previously-prose-only claims in
`docs/architecture/ARCHITECTURE_RULES.md` into executable regression tests:

- Rule 11 layer dependency direction (8 data sets)
- Rule 2 Domain framework independence
- Rule 12 no Model -> Http dependency
- zero service locators in Domain and Application
- exact-pinning of the 18 pre-existing locator call sites

## Debt pinning rather than deletion
Known debt is pinned *exactly* (file + call site), so the suite fails both when
new debt appears **and** when pinned debt silently disappears. That makes the
debt register self-verifying without ever asserting the debt is desirable.

| Pinned debt | Count | Rule |
|---|---|---|
| `CampaignPricingEngine` -> `App\Services` | 7 imports | 11 |
| Service-locator call sites | 18 across 9 files | 11 |

## Not done, and why
- No service locator removed: all 18 are Category B or C.
- `CampaignPricingEngine` not decoupled: changes campaign pricing arithmetic.
- No controller split: `BookingController` is 1414 lines; splitting changes envelopes.
- `Domain`/`Application` facade consolidation: 62 call sites in reporting read paths.

## Verification
```
php artisan test tests/Unit/Architecture/ArchitectureRuleTest.php
# 14 passed (17 assertions), 0.29s
```

## Detector defects found and fixed during this phase
Worth recording because they are the kind of bug that makes a static analyser
silently useless:

1. Previous-token lookup scanned **forwards**, so a `public static function
   resolve(` **declaration** was reported as a container call. It also
   defeated every `::` / `->` / `function` guard, which would have made the
   facade and import checks unreliable. Fixed by scanning backwards.
2. PHP 8 emits `Foo\Bar` as one `T_NAME_QUALIFIED` **array** token, so
   concatenating it raised "Array to string conversion" and produced no
   import data. Fixed by reading `token[1]`.
3. Re-tokenising the file on every lookup made the suite take 13.69s. A
   per-file cache reduced it to 0.29s.

