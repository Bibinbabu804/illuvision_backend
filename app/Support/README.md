# Support

Cross-cutting technical concerns. **No business semantics.**

## Subdirectories

| Directory | Owns |
|---|---|
| `Events` | application event subscribers and `Notifications` event family |
| `Jobs` | queued jobs |
| `Exceptions` | technical / cross-cutting exceptions |

## Rules

- `Support` must not import `Domain`, `Services`, `Http` or `Console`.
- `Support` must not contain business rules, Eloquent queries, or external API calls.
- A class only belongs here if it is genuinely layer-agnostic. Business exceptions
  live with their domain, e.g. `Domain/Availability/Exceptions/FleetCapacityExceededException`
  and `Domain/Pricing/Exceptions/PricingException`.
- Event subscribers may depend on `Services` and `Domain` contracts — that is the
  one sanctioned direction *out* of `Support/Events`, because subscribers are
  delivery-side consumers.

## Current state

- `Events/Notifications` — 33 notification events.
- `Events/NotificationEventSubscriber` — the single subscriber that consumes them.
- `Exceptions/UserConflictException` — cross-cutting user conflict, used by
  registration, login and role management.
- `Jobs` — empty; no queued job classes exist yet.
