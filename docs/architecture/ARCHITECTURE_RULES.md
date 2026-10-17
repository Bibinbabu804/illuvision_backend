# Core Architecture Rules & Invariants

## Illuvision Core Backend — Re-Architecture Standards

The following architectural rules govern all refactoring and feature additions across the Illuvision backend:

### Rule 1: HTTP Layer SDK Decoupling
The HTTP Presentation layer (`app/Http/Controllers`) must not directly instantiate or depend on third-party external SDKs or low-level HTTP clients (`Razorpay\Api\Api`, `Http::get(...)` to third-party endpoints). Controllers must delegate external operations to dedicated Infrastructure Adapters or Application Use Cases.

### Rule 2: Pure Domain Invariants
Domain entities and core calculation engines (`PricingEngine`, `RevenueDistributionEngine`, `CommissionEngine`) must never perform external network calls. All calculations must be deterministic, pure, and based exclusively on in-memory state or passed value objects.

### Rule 3: Vendor Encapsulation in Infrastructure
Infrastructure Adapters (`app/Infrastructure/`) own all vendor-specific initialization, exception handling, data structures, and SDK wrappers. Vendor SDK classes (such as Razorpay, Google OAuth, AWS S3) must never leak into Domain or Application layer signatures.

### Rule 4: Explicit Financial Transaction Boundaries
Financial workflows (payment verification, booking status transitions, revenue distribution, settlements) must maintain explicit database transaction boundaries (`DB::transaction(...)`). External network operations must be isolated outside database row locks to prevent connection pool exhaustion and lock timeouts.

### Rule 5: Stable External API Contracts
Existing external API contracts (routes, HTTP methods, request fields, validation rules, response structure, status codes, and error formats) must remain stable during all internal refactoring phases. Architectural changes must refactor internal implementation without altering external contracts.

### Rule 6: High-Value Repository Abstraction Only
Avoid blanket repository boilerplate for simple Active Record models. Repository boundaries (`ScreenRepositoryInterface`, `BookingRepositoryInterface`, `PaymentRepositoryInterface`) are introduced only where persistence isolation, complex query encapsulation, or testability benefits are demonstrably justified.

### Rule 7: Zero Business Logic Duplication
Never copy large blocks of existing business logic into new use cases. Follow the strangler pattern: wrap and delegate to existing services first, verify regression equivalence with golden suites, and then progressively extract deeper domain behavior.

### Rule 8: Modular Monolith Cohesion
All refactoring occurs within the unified Laravel modular monolith. Do not introduce microservices, event sourcing, or distributed database splits during the architecture migration.

### Rule 9: Pricing Domain Autonomy and Determinism
The Pricing domain (`app/Domain/Pricing`) must never depend directly on HTTP requests, session state, external payment APIs, or database transaction orchestration. Pricing calculations must be deterministic: identical parameters and pricing configurations must yield identical financial outputs.

### Rule 10: Legacy Compatibility Isolation via Adapters
Where divergent legacy calculation paths or API schemas exist (such as legacy V2 multi-asset endpoints), legacy algorithms must remain isolated behind application adapters (`app/Application/*/Adapters`) without algorithm duplication. Legacy engines must not leak into canonical domain models, and must only be retired after product authorization and consumer migration.

### Rule 11: Strict Layer Dependency Direction
Dependencies must flow in one direction only: `HTTP -> Application -> Domain -> Contracts -> Infrastructure -> Persistence/External`. An inner layer may never reference an outer layer's implementation. Specifically: `Domain` and `Application` must not import `App\Http`; a Model (Active Record) must not reference the HTTP layer; and `Application` may orchestrate legacy `App\Services` only in the transitional strangler state described by Rule 7, never the reverse. Where a boundary is genuinely mis-modelled, fix the dependency rather than relocating the file — moving a class that takes Eloquent models into `Domain` converts a visible violation into a subtler one.

> Added in Phase 0.14. This rule was previously unstated but was already being enforced in practice; Phases 0.13–0.14 found and fixed a Model -> HTTP inversion (`User::hasPermission` resolving `PermissionMiddleware`) that no existing rule covered.

### Rule 12: Authorization at the Route Boundary
Authorization is enforced by the route/middleware layer, which is the authoritative check. The permission algorithm must have exactly one owner, shared by every caller (middleware, models, services) — never duplicated per caller, and never owned by the transport layer. Deliberate asymmetries between the authoritative route check and convenience helpers (for example a super-admin bypass that exists only in middleware) must be preserved and pinned by tests until an explicit authorization change is approved, since altering them changes authorization behaviour.

> Added in Phase 0.14, following the extraction of `app/Support/Authorization/RolePermissionChecker`.

### Rule 13: Verified Deletion
A class may be deleted only after **both** checks pass: (1) reference counting across `app/`, `routes/`, `tests/`, `database/`, `config/` and `bootstrap/`, and (2) framework-registration verification against `php artisan list`, `routes/console.php`, the route table, event listener registrations and Eloquent relations. Reference counting alone is insufficient, because Laravel resolves console commands, scheduled tasks, listeners, relations, policies and providers by convention rather than by import. A rule implementation may not be removed until its canonical owner is explicitly designated (see also Rule 10).

> Added in Phase 0.14 after a reference-count scan flagged three *live* scheduled console commands (`rewards:expire`, `bookings:expire-pending`, `currency:sync-rates`) that a purely lexical scan would have deleted.

