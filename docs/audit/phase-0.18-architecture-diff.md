# Phase 0.18 — Modular Monolith Migration: Architecture Diff

- **Date:** 2026-09-26
- **Project:** `illuvision/core-backend`
- **Style:** modular monolith (no microservices, no CQRS, no event sourcing)
- **Behaviour change:** none
- **Status:** **PARTIAL** — 3 classes migrated, 45 sequenced and deferred

## Summary

Phase 0.18 audited the whole application against the target modular architecture,
established the first genuinely missing module (`Application/Asset`) and the first
missing `Support/Events` home, and produced a gated, ordered plan for the remaining
45 `Services` classes. No financial, availability, booking, payment, refund or
settlement class was moved, because the brief lists all of those as hard stops.

## Files moved

```
MOVED
  app/Listeners/NotificationEventSubscriber.php
    -> app/Support/Events/NotificationEventSubscriber.php
       App\Listeners -> App\Support\Events

  app/Services/AssetDiscoveryFilterService.php
    -> app/Application/Asset/Services/AssetDiscoveryFilterService.php
       App\Services -> App\Application\Asset\Services

  app/Services/CoverageService.php
    -> app/Application/Asset/Services/CoverageService.php
       App\Services -> App\Application\Asset\Services

MODIFIED (import only, no logic)
  app/Providers/EventServiceProvider.php
  app/Services/AssetService.php        (+2 use statements)
  app/Http/Controllers/Api/CoverageController.php

REMOVED
  app/Listeners/            (directory, now empty)
```

No file was added or deleted; 3 files changed location.

## Why only these three

Each moved class depends **only on contracts** — no Eloquent models, no `Domain`
back-references, no `Http`, no service locators:

| Class | Lines | Only dependency | Consumers |
|---|---|---|---|
| `AssetDiscoveryFilterService` | 33 | `AssetRepositoryInterface` | 1 |
| `CoverageService` | 23 | `AssetRepositoryInterface` | 2 |
| `NotificationEventSubscriber` | ~60 | framework Event Dispatcher | 1 |

That property is what made the moves verifiable to the required certainty. The other
45 `Services` classes do not share it: 18 are explicit financial/booking/payment/
settlement/availability hard stops, and the remainder are Eloquent-shaped, meaning
moving them into `Application/` before extracting a persistence port would place
`Models` inside `Application` and make ownership **less** clear — which Rule 26 of
`ARCHITECTURE_RULES.md` explicitly prohibits.

## Class census

| Layer | Phase 0.17 end | Phase 0.18 end | Δ |
|---|---|---|---|
| Application | 32 | 34 | **+2** |
| Listeners | 1 | 0 | **−1** |
| Services | 48 | 46 | **−2** |
| Support | 9 | 10 | **+1** |
| *all others* | — | — | 0 |
| **Total** | **336** | **336** | **0** |

## Target directory coverage

2 of 26 probed target directories exist, both created in this phase
(`app/Application/Asset`, `app/Support/Events`). No empty directories were created to
inflate conformance — Section 3 of the brief forbids it.

`app/Domain` remains the thinnest layer (9 classes, all of it `Payment`, `Pricing`
and `Financial`). `app/Application/Reporting` (17 classes) is a read-side module
without a Domain counterpart, which is acceptable for query models.

## Dependency conformance

| Rule | State | Δ |
|---|---|---|
| Rule 2 — Domain must not import Services | RED, 7 imports pinned to `CampaignPricingEngine` | none |
| Rule 2 — Domain must not import Console / Infrastructure / Application | GREEN | none |
| Rule 11 — Domain must not import Http | GREEN | none |
| Rule 11 — no service locators in Domain / Application | GREEN (0 / 0) | none |
| Rule 12 — service-locator debt does not grow | RED, 8 classes pinned | none |
| Domain uses no Illuminate facade | GREEN | none |

No illegal edge and no cycle was introduced.

## New finding: an unenforced rule

`Domain → Models` is **not** enforced by `ArchitectureRuleTest`. The `LAYER_IMPORTS`
forbidden list for `Domain` is `[App\Http, App\Console, App\Infrastructure,
App\Application, App\Services, App\Http\Controllers]` — `App\Models` is absent,
although the target architecture says Domain must not import Models. Five Domain
files do import it:

- `Domain/Financial/Contracts/SettlementRepositoryInterface.php`
- `Domain/Financial/Contracts/PartnerCommissionRepositoryInterface.php`
- `Domain/Payment/Contracts/PaymentRepositoryInterface.php`
- `Domain/Payment/PaymentStatus.php`
- `Domain/Pricing/CampaignPricingEngine.php`

The test was **not** weakened and no rule was removed. The recommended follow-up is
to add the check with an explicit 5-class allowlist so the debt is visible and cannot
grow, then remove it class by class via DTO-returning repository contracts.

## A real break that was caught

Moving `CoverageService` and `AssetDiscoveryFilterService` out of `App\Services`
silently broke `App\Services\AssetService`, which type-hinted both from its own
namespace and therefore had no import statement to rewrite:

```
BindingResolutionException: Target class [App\Services\CoverageService] does not exist.
```

Both *greps for stale references and resolving each migrated class in isolation had
passed*. Only resolving the **consumer** through the container exposed it. The lesson
is recorded in `phase-0.18-namespace-migration-map.json`: namespace moves must be
verified by resolving every consumer, because same-namespace consumers are invisible
to an import grep.

## Verification

| Check | Result |
|---|---|
| Full test suite | 571 passed, 2 failed, 1 warning, 4 skipped (identical) |
| Assertions | 5,323 (identical) |
| Routes | 385, 0 differences in method / URI / action / middleware |
| Schema | 74 / 796 / 252 / 94 (identical) |
| Migrations | 112 applied, 0 pending |
| Architecture test | 14 passed, 17 assertions |
| Stale references to moved classes | 0 |

## Sequenced remainder

Full plan with per-move verification gates is in
`phase-0.18-application-module-map.json`. Order: `MediaService` → `CurrencyService`
and `ExchangeRateService` → `ProfileService` and `CountryDetectionService` →
`NotificationService` → read-side analytics into `Application/Reporting` →
`SettingsService` → Eloquent-heavy Asset/Provider/Partner services (only *after*
extracting a persistence port).
