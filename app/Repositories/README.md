# Repositories

Repository **contracts** and their **persistence implementations**, per capability.

## Capabilities

`Campaign` `Asset` `Booking` `Pricing` `Payment` `Creative`

## Dependency direction

```
Domain / Services
      |  depends on the INTERFACE only
      v
Repository Interface
      |
      v
Repository Implementation (Eloquent)
      |
      v
MySQL
```

## Rules

- The contract/implementation distinction must remain explicit and separate.
- `Models` are the persistence-model layer and stay in `app/Models`.
- A service that performs Eloquent queries directly is **not** renamed to
  `*Repository`. Extract the contract first, migrate consumers, then remove the old
  path — see `docs/audit/phase-0.20-repository-module-map.json`.
- Implementations may later move to `Infrastructure/Persistence/`; that is a naming
  refinement, not a behaviour change.
- Repository interfaces must not import `Http`.

## Current state

| Capability | Contract | Implementation |
|---|---|---|
| Asset | `Repositories/Asset/AssetRepositoryInterface` | `Repositories/Asset/AssetRepository` (Eloquent) |
| Booking | `Repositories/Booking/BookingRepositoryInterface` | `Repositories/Booking/BookingRepository` (Eloquent) |
| Campaign | — | not extracted (P2 debt) |
| Pricing | — | not extracted (P2 debt) |
| Payment | — | not extracted (P2 debt) |
| Creative | — | not extracted (P2 debt) |

Both implementations were intentionally **not** renamed (`AssetRepository` stays
`AssetRepository`) because renaming without changing responsibility is forbidden.
The `*Interface` / concrete pair already provides the explicit distinction.
