# Http

Delivery layer: routes, controllers, form requests, API resources, middleware.

## Structure

```
app/Http/
  Controllers/        web + admin controllers
  Controllers/Api/    /api/v2/* JSON endpoints
  Controllers/Auth/   authentication flows
  Requests/           FormRequest validation
  Resources/          JsonResource response shaping
  Middleware/         HTTP middleware
```

## Subdirectory policy (plan section 15)

Subdirectories are introduced **only when they reflect an existing route/controller
boundary.**

- `Api/` and `Auth/` exist because they map to real, distinct route groups.
- `Admin/`, `Partner/` and `Provider/` were **not** created. Every route in this
  application is registered under the `/api` prefix; the admin/partner/provider
  distinction currently exists only as a controller *naming* convention
  (`AdminPaymentController`, `PartnerPortalController`, `ScreenProviderPortalController`),
  not as a route or filesystem boundary. Creating directories for them would be
  name-based reorganisation, not a boundary, and would churn 30+ classes to improve a
  conformance percentage.

Current counts: 48 controllers (flat), 17 in `Api/`, 3 in `Auth/`, 5 `Requests`,
7 `Resources`, 1 `Middleware`.

## Rules

- Controllers orchestrate and delegate; business rules belong in `Domain` /
  `Services`.
- Controllers must not contain SQL or Eloquent queries.
- Controllers must not import `Domain` directly — use `Services` / `Repositories`
  contracts. The known exceptions are pinned in
  `tests/Unit/Architecture/ArchitectureRuleTest.php`.
- `Resources` shape output only; they must not query the database.
- Every route is part of the public API contract. The 385-route snapshot in
  `docs/audit/routes-baseline.json` is immutable during architecture migration.
