# Domain

Business rules, invariants, value objects and domain contracts.

## Domains (Project 01)

`Campaign` `Media` `Asset` `Provider` `Partner` `Pricing` `Availability`
`Booking` `Payment` `Settlement` `Reporting` `Creative`

`Creative` is a **bounded domain inside Project 01** — not a separate application —
and is subdivided into `Creator` `Service` `Brief` `Proposal` `Project`
`Revision` `Delivery` `Review` `Earnings`.

## Allowed dependencies

- other `Domain/*` classes
- `app/Support/*` (value helpers, exceptions)
- framework primitives (collections, `Carbon`, `Money`)

## Prohibited

- `Services`, `Http`, `Console`, `Infrastructure`, `Application`, `Models`
- Eloquent (`Illuminate\Database\...`), Facades, `app()` / `resolve()` locators

## Examples

- `Pricing/CampaignPricingEngine` — campaign pricing rules
- `Payment/PaymentStatus` — payment state vocabulary
- `Financial/Support/RevenueAllocation` — commission allocation
- `Asset/Events/AssetCreated` — asset lifecycle event
- `Availability/Exceptions/FleetCapacityExceededException` — capacity rule violation

## Known controlled debt

- 7 imports of `App\Services\*` inside `Pricing/CampaignPricingEngine`, pinned by
  `tests/Unit/Architecture/ArchitectureRuleTest.php`.
- 4 `Domain` files import `App\Models`. **This is not yet enforced** by the
  architecture test — see `docs/audit/phase-0.20-domain-module-map.json`.
