# Infrastructure

External systems, persistence implementations and framework adapters.

## Target subdirectories

`Persistence` `Payments` `Storage` `Notifications` `ExchangeRates` `ExternalServices`

## Ownership

| Directory | Owns |
|---|---|
| `Persistence` | Eloquent repository implementations, query implementations, DB-specific adapters |
| `Payments` | Razorpay adapter, future payment gateway adapters |
| `Storage` | filesystem / S3 / media storage adapters, file storage |
| `Notifications` | mail, WhatsApp and other external notification adapters |
| `ExchangeRates` | exchange-rate client and provider-specific API implementation |
| `ExternalServices` | other third-party integrations |

## Established submodules retained

`Auth`, `Financial` and `Media` are **kept**, not flattened, because they are
semantically correct owners of real capability boundaries:

- `Auth` — Google OAuth (used by `Auth/FacebookAuthProvider`)
- `Financial` — RevenueCycle, commission, wallet, payout calculations
- `Media` — media/asset geometry helpers, model attributes and file handling

`Media/MediaHelper`, `Media/Support` and `Media/Filters` remain appropriate in
`Media`; only the *storage adapter* left `Media` for `Storage`, which is where a
storage contract belongs.

## Rules

- Every external system needs a **contract** plus an adapter; the contract is what
  the rest of the codebase depends on.
- Contracts and adapters of the same capability stay together in one directory
  (e.g. `Storage/Contracts` + `Storage/LaravelStorageAdapter`), so the boundary
  rule is auditable by a single filesystem listing.
- No `Http` or `Console` dependencies from `Infrastructure`.
- Bindings are declared in `app/Providers/AppServiceProvider.php` and must be
  verified with `php artisan tinker` after any move.
