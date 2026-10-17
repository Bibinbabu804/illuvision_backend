# Phase 0.20 - Target Structure vs Current Structure

**Captured:** 2026-09-26

Legend: `CREATED (empty)` = the plan requires the directory and it intentionally holds no class yet.

## Top level

| Target path | Plan | Current | Notes |
|---|---|---|---|
| `app/Domain` | REQUIRED | PRESENT, 0 classes | |
| `app/Services` | REQUIRED | PRESENT, 28 classes | |
| `app/Repositories` | REQUIRED | PRESENT, 0 classes | |
| `app/Infrastructure` | REQUIRED | PRESENT, 0 classes | |
| `app/Support` | REQUIRED | PRESENT, 2 classes | |
| `app/Http` | REQUIRED | PRESENT, 0 classes | |
| `app/Models` | not in plan (retained, justified) | PRESENT, 62 classes | |
| `app/Providers` | not in plan (retained, justified) | PRESENT, 2 classes | |
| `app/Console` | not in plan (retained, justified) | PRESENT, 0 classes | |
| `app/Mail` | not in plan (retained, justified) | PRESENT, 28 classes | |
| `app/Enums` | not in plan (retained, justified) | PRESENT, 1 classes | |
| `app/Helpers` | not in plan (retained, justified) | PRESENT, 1 classes | |
| `app/Observers` | not in plan (retained, justified) | PRESENT, 1 classes | |
| `app/Application` | not in plan (retained, justified) | PRESENT, 0 classes | |

## Domain

| Target path | Status | Classes | Notes |
|---|---|---|---|
| `app/Domain/Campaign` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Media` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Asset` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Provider` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Partner` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Pricing` | PRESENT | 1 | 4 classes |
| `app/Domain/Availability` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Booking` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Payment` | PRESENT | 1 | 4 classes |
| `app/Domain/Settlement` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Reporting` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative/Creator` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative/Service` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative/Brief` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative/Proposal` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative/Project` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative/Revision` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative/Delivery` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative/Review` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Domain/Creative/Earnings` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |

## Services

| Target path | Status | Classes | Notes |
|---|---|---|---|
| `app/Services/Campaign` | PRESENT | 13 | 13 classes |
| `app/Services/Booking` | PRESENT | 1 | 1 classes |
| `app/Services/Pricing` | PRESENT | 4 | 4 classes |
| `app/Services/Payment` | PRESENT | 1 | 1 classes |
| `app/Services/Creative` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Services/Settlement` | PRESENT | 1 | 1 classes |

## Repositories

| Target path | Status | Classes | Notes |
|---|---|---|---|
| `app/Repositories/Campaign` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Repositories/Asset` | PRESENT | 2 | 2 classes |
| `app/Repositories/Booking` | PRESENT | 2 | 2 classes |
| `app/Repositories/Pricing` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Repositories/Payment` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Repositories/Creative` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |

## Infrastructure

| Target path | Status | Classes | Notes |
|---|---|---|---|
| `app/Infrastructure/Persistence` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Infrastructure/Payments` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Infrastructure/Storage` | PRESENT | 1 | 2 classes |
| `app/Infrastructure/Notifications` | PRESENT | 1 | 2 classes |
| `app/Infrastructure/ExchangeRates` | PRESENT | 1 | 2 classes |
| `app/Infrastructure/ExternalServices` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |

## Support

| Target path | Status | Classes | Notes |
|---|---|---|---|
| `app/Support/Events` | PRESENT | 1 | 34 classes |
| `app/Support/Jobs` | CREATED (empty) | 0 | intentionally empty - capability declared, not yet implemented |
| `app/Support/Exceptions` | PRESENT | 1 | 1 classes |

## Http

| Target path | Status | Classes | Notes |
|---|---|---|---|
| `app/Http/Controllers` | PRESENT | 48 | 68 classes |
| `app/Http/Requests` | PRESENT | 5 | 5 classes |
| `app/Http/Resources` | PRESENT | 7 | 7 classes |
