# Phases 0.15-0.16 - Production Changes

Generated 2026-09-26

## Production code changed: none

No file under `app/`, `config/`, `routes/`, `database/` or `resources/views`
was modified in Phase 0.15 or Phase 0.16.

## Test code changed

| File | Change | Detail |
|---|---|---|
| `tests/Unit/Architecture/ArchitectureRuleTest.php` | **added** | 14 cases, 17 assertions, 0.29s |

That is the entire code diff. It is deliberate: see
`phase-0.15-0.16-architecture-decisions.md` AD-01.

## What the new test enforces

- Rule 11 layer dependency direction (8 data sets)
- Rule 2 - Domain uses no framework facade
- Rule 12 - no Model imports `App\Http`
- zero service locators in Domain and Application
- the 18 known locator call sites, pinned exactly
- the 7 known `CampaignPricingEngine -> Services` imports, pinned exactly
- `RolePermissionChecker` is the single permission owner

## Temporary probes created and removed

Three throwaway probes were used and deleted; none remain in the tree:

| Probe | Purpose | Disposition |
|---|---|---|
| `tests/Feature/Security/PatchBypassProofTest.php` | Prove the PATCH permission bypass at runtime | Deleted; evidence recorded in `phase-0.16-security-baseline.json` |
| `tests/Unit/PerformanceProbeTest.php` | Measure query counts and timings | Deleted; results recorded in `phase-0.16-performance-baseline.json` |
| `/tmp/p016/tok.php` and scan outputs | Token-based static inventories | Outside the repo |

A probe was used for SEC-01 deliberately: the finding contradicts an earlier
conclusion of mine, so it was verified by execution rather than by reading the
route table. The probe wrote `status=approved` and `price=1.00` to a test
booking, which is exactly the impact claimed.

## Artifacts created: 33

- 10 x `phase-0.15-*`
- 16 x `phase-0.16-*`
- 7 x `phase-0.15-0.16-*`
