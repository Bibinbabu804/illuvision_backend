# Phase 0.11–0.12 — Architecture Decisions

## Decision 1: Reporting Query Layer

### Context
The ReportService was accumulating multiple responsibilities, acting as a god service for all reporting needs.

### Current Behavior
ReportService contained 9 different reporting methods, each making calls to various analytics services and containing some direct SQL queries.

### Target Boundary
Extract reporting methods into focused query objects in the application layer that depend on canonical read models.

### Alternatives Considered
1. Keep ReportService as-is (rejected - violates single responsibility principle)
2. Move all reporting logic to domain layer (rejected - reporting is application concern)
3. Create reporting services in infrastructure layer (rejected - mixes concerns)

### Decision
Create focused query objects in app/Application/Reporting/ that each handle one reporting responsibility.

### Reason
Improves separation of concerns, makes code more testable, and follows the application layer pattern established in previous phases.

### Compatibility Impact
None - API contracts remain unchanged

### Performance Impact
Neutral - same queries will be executed

### Migration Risk
Low - mechanical extraction of existing methods

### Deferred Work
None for this decision

---

## Decision 2: Canonical Data Consumption for Reporting

### Context
Reporting services were consuming data from various analytics services, which in turn queried models directly.

### Current Behavior
Reporting indirectly consumed canonical data through analytics services, but some complex queries were embedded in ReportService.

### Target Boundary
All reporting must consume canonical persisted state from transactional services, never recalculate business rules.

### Alternatives Considered
1. Allow reporting to recalculate business rules (rejected - violates architectural principle)
2. Create duplicate calculation logic in reporting (rejected - creates inconsistency)
3. Extract canonical read models for reporting consumption (chosen)

### Decision
Ensure reporting queries depend on canonical read models that represent the persisted state of transactional domains.

### Reason
Maintains data consistency between transactional and reporting systems, prevents drift where reporting shows different numbers than transactional systems.

### Compatibility Impact
None - underlying data and calculations remain the same

### Performance Impact
Potentially improved - canonical read models can be optimized for reporting queries

### Migration Risk
Low - mainly involves redirecting dependencies

### Deferred Work
None

---

## Decision 3: Integration Adapter Reuse

### Context
Previous phases (0.5) established infrastructure adapters for external services.

### Current Behavior
Some direct external service calls may exist in application or domain layers.

### Target Boundary
All external service calls must go through existing infrastructure adapters.

### Alternatives Considered
1. Create new adapters for each service (rejected - creates duplication)
2. Allow direct calls when convenient (rejected - violates boundary)
3. Reuse and potentially refactor existing adapters (chosen)

### Decision
Verify existing adapters are correctly placed and use them for all external service calls.

### Reason
Leverages work already done, maintains consistency, reduces surface area for bugs.

### Compatibility Impact
None - external behavior preserved

### Performance Impact
None - same underlying calls

### Migration Risk
Low - mainly involves redirecting existing calls

### Deferred Work
None

---

## Decision 4: Notification Boundary

### Context
Notification logic was scattered across services, event listeners, and controllers.

### Current Behavior
Notifications were sent from various places in the codebase, sometimes directly using Mail:: or Storage:: facades.

### Target Boundary
Create a clear notification boundary: Business Event → Notification Handler → Notification Contract → Channel Adapter.

### Alternatives Considered
1. Keep scattered notification logic (rejected - hard to maintain and track)
2. Create a single monolithic notification service (rejected - doesn't handle channel differences well)
3. Create handler-contractor-adapter pattern for notifications (chosen)

### Decision
Implement the notification boundary using the handler-contractor-adapter pattern.

### Reason
Provides clear separation, makes notification behavior predictable, and allows easy addition of new channels.

### Compatibility Impact
None - notification timing and behavior preserved

### Performance Impact
Neutral - same underlying operations

### Migration Risk
Medium - requires identifying all notification sending locations

### Deferred Work
None for boundary creation, but actual migration of notification sending logic deferred

---

## Decision 5: Webhook Boundary

### Context
Webhook processing logic was potentially mixed with controller logic.

### Current Behavior
Some webhook endpoints may contain complex processing logic directly in controllers.

### Target Boundary
Webhook Controller → Webhook Application Handler → Provider Adapter → Domain Event

### Alternatives Considered
1. Keep complex logic in controllers (rejected - violates separation of concerns)
2. Put all logic in domain services (rejected - webhook handling is application concern)
3. Create clear webhook boundary with handler and adapter layers (chosen)

### Decision
Extract webhook processing logic from controllers into application handlers, with providers accessed through infrastructure adapters.

### Reason
Follows established patterns, keeps controllers thin, makes webhook behavior testable.

### Compatibility Impact
None - webhook contracts (request/response formats) preserved

### Performance Impact
Neutral - same underlying processing

### Migration Risk
Low - mainly involves moving existing logic

### Deferred Work
None

---

## Decision 6: Storage/Media Boundary

### Context
Media processing and storage usage was potentially scattered.

### Current Behavior
Direct Storage:: and GD/Image intervention calls may exist in application or domain layers.

### Target Boundary
Media/Application → Storage Contract → Local/S3 Adapter
and
Image Processing Contract → GD/Intervention Adapter

### Alternatives Considered
1. Keep direct storage/media calls (rejected - makes switching implementations hard)
2. Create direct wrappers for each usage (rejected - doesn't provide clean abstraction)
3. Create contract-adapter pattern for storage and media processing (chosen)

### Decision
Implement storage and media processing boundaries using contract-adapter patterns.

### Reason
Allows switching storage providers (local/S3) and image processing libraries (GD/Intervention) without changing application code.

### Compatibility Impact
None - file paths, URLs, and image processing behavior preserved

### Performance Impact
Neutral - same underlying operations

### Migration Risk
Low - mainly involves redirecting existing calls

### Deferred Work
None

---

## Decision 7: Queue/Job Ownership

### Context
Job ownership was potentially unclear - some jobs may contain business workflows.

### Current Behavior
Some jobs may contain significant business logic rather than just dispatching to use cases.

### Target Boundary
Jobs should generally invoke application use cases rather than contain large business workflows.

### Alternatives Considered
1. Allow jobs to contain business workflows (rejected - makes jobs hard to test and maintain)
2. Move all business logic to jobs (rejected - confuses job responsibility with application responsibility)
3. Jobs as thin dispatchers to application use cases (chosen)

### Decision
Ensure jobs invoke application use cases rather than containing business workflows.

### Reason
Follows single responsibility principle, makes jobs easier to test and monitor.

### Compatibility Impact
None - job behavior preserved

### Performance Impact
Neutral - same underlying operations

### Migration Risk
Low - mainly involves refactoring existing jobs

### Deferred Work
None for principle, but actual job refactoring deferred

---

## Decision 8: Date/Time Semantics Preservation

### Context
Reporting frequently introduces subtle errors through date filtering.

### Current Behavior
Various date fields are used: created_at, start_time, end_time, paid_at, etc., with different semantics.

### Target Boundary
Document existing date/time semantics without altering behavior.

### Alternatives Considered
1. Standardize all date usage to a single field (rejected - would change behavior)
2. Ignore date semantics issues (rejected - leads to reporting inaccuracies)
3. Document and preserve existing semantics (chosen)

### Decision
Preserve existing date/time semantics and document them for consistency.

### Reason
Changing date semantics would alter reported values, violating the non-behavior-change principle of this phase.

### Compatibility Impact
None - behavior preserved

### Performance Impact
None

### Migration Risk
None - documentation only

### Deferred Work
None