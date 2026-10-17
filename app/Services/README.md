# Services

**Application / use-case orchestration.** A class belongs here when it primarily
orchestrates a use case, a workflow, several domain operations, a transaction, or
integration coordination.

## Capabilities

`Campaign` `Booking` `Pricing` `Payment` `Creative` `Settlement`

## Allowed dependencies

- `Domain` contracts and value objects
- `Repositories/*` contracts
- `Infrastructure/*` **contracts** — never a concrete adapter where a contract exists
- `Models` (pragmatic: Eloquent-backed orchestration)
- `Support`

## Prohibited

- `Http` — a controller must never be referenced from here
- raw external SDK / HTTP calls; go through an `Infrastructure` contract
- business invariants that belong in `Domain`

## Rules

- `Services` must not become a flat dumping ground. Anything still directly in
  `app/Services/*.php` has a documented reason in
  `docs/audit/phase-0.20-legacy-directory-status.json`.
- `P3` (financial) and `P4` (transaction/concurrency) classes are **not** migrated
  merely because a target directory now exists.
- Availability *check* (`BookingCapacityService`) and transactional inventory *lock*
  (`InventoryService`) are deliberately separate and must not be merged.
- Service-locator use is restricted to the 5 bootstrapped model-event edges listed
  in `docs/audit/phase-0.20-service-locator-usage.json`.

## Examples

- `Campaign/ClientService` — client CRUD orchestration
- `Campaign/CurrencyService` — currency + rate coordination
- `Booking/BookingAnalyticsService` — booking analytics use case
- `Campaign/AssetDiscoveryFilterService` — asset discovery + coverage filtering
