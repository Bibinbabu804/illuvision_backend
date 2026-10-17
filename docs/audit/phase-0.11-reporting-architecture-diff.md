# Phase 0.11 — Reporting Architecture Diff

## Current State (Before Extraction)

```
HTTP Layer
    ↓
ReportController
    ↓
ReportService
    ↓
RevenueAnalyticsService, BookingAnalyticsService, ScreenAnalyticsService, etc.
    ↓
Eloquent Models (Booking, Payment, Screen, etc.) + Direct DB Queries
    ↓
Database
```

### Issues with Current State:
1. **ReportService acts as a god service** - aggregates too many responsibilities
2. **Direct DB queries in complex reports** - getScreenProviderReport contains complex SQL that should be encapsulated
3. **Some business logic duplication** - similar date range handling across multiple services
4. **Mixed concerns** - some services handle both analytical calculations and data retrieval

## Target State (After Extraction)

```
HTTP Layer
    ↓
ReportController
    ↓
Reporting Application Layer (Use Cases/Queries)
    ↓
Canonical Read Sources
    ↓
Database
```

### Specific Changes:
1. **ReportService will be decomposed** into focused reporting queries:
   - OverviewQuery
   - RevenueReportQuery
   - BookingReportQuery
   - ScreenReportQuery
   - etc.

2. **Analytics services will remain** but will be refined to ensure they only contain canonical calculations:
   - RevenueAnalyticsService (financial calculations)
   - BookingAnalyticsService (booking calculations)
   - ScreenAnalyticsService (screen calculations)
   - PaymentAnalyticsService (payment calculations)
   - ClientAnalyticsService (client calculations)

3. **Complex SQL queries will be encapsulated** in query objects or repository methods:
   - ScreenProviderReportQuery (replacing direct DB queries in ReportService)
   - Other specialized queries as needed

4. **Clear separation between**:
   - Transactional services (handle mutations)
   - Reporting/analytical services (read-only calculations)
   - Infrastructure (data access implementations)

## Boundary Diagrams

### Before:
```
Controller
    ↓
ReportService (God Service)
    ↓
[RevenueAnalyticsService + BookingAnalyticsService + ScreenAnalyticsService + ...]
    ↓
[Booking Model + Payment Model + Screen Model + Direct DB Queries]
    ↓
Database
```

### After:
```
Controller
    ↓
Reporting Query Layer
    ↓
[Canonical Financial Read Model]
    ↓
[Canonical Booking Read Model]
    ↓
[Canonical Screen Read Model]
    ↓
[Infrastructure Repositories]
    ↓
Database
```

## Rule Compliance Table

| Rule | Current State | Target State | Status |
|------|---------------|--------------|---------|
| Controllers delegate to services | ✓ | ✓ | COMPLIANT |
| Services contain business logic | ✓ | ✓ | COMPLIANT |
| No direct infrastructure leaks in domain/application | ✓ | ✓ | COMPLIANT |
| Reporting consumes canonical data | ○ (mostly) | ● | TO BE VERIFIED |
| Clear separation of read/write concerns | ○ | ● | TO BE ACHIEVED |
| Services are framework-independent where possible | ○ | ● | IMPROVEMENT NEEDED |

## Migration Approach

1. **Identify reporting queries** - extract methods from ReportService into focused query objects
2. **Verify canonical data consumption** - ensure reporting services use canonical financial data
3. **Encapsulate complex SQL** - move direct DB queries to appropriate repository methods
4. **Maintain API contracts** - ensure all endpoint responses remain identical
5. **Add tests** - verify that reporting outputs match expectations

## Files That Will Be Created/Moved

### New Files:
- `app/Application/Reporting/OverviewQuery.php`
- `app/Application/Reporting/RevenueReportQuery.php`
- `app/Application/Reporting/BookingReportQuery.php`
- `app/Application/Reporting/ScreenReportQuery.php`
- `app/Application/Reporting/ScreenProviderReportQuery.php`
- `app/Domain/Reporting/Contracts/ReportRepositoryInterface.php`
- `app/Infrastructure/Reporting/EloquentReportRepository.php`

### Refactored Files:
- `app/Services/ReportService.php` - decomposed into query objects
- `app/Http/Controllers/ReportController.php` - updated to use query objects

### Unchanged Files (but verified):
- All analytics services (RevenueAnalyticsService, BookingAnalyticsService, etc.)
- All Eloquent models
- All infrastructure adapters

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| API contract changes | Low | High | Use golden contracts to verify outputs |
| Behavioral changes in calculations | Low | High | Compare outputs before/after extraction |
| Performance degradation | Medium | Medium | Ensure indexes exist, maintain query efficiency |
| Missing dependencies | Low | Low | Comprehensive dependency mapping |

## Compatibility Guarantees

✅ API contracts will be preserved
✅ Response formats will be identical
✅ Status codes will be unchanged
✅ Authentication/authorization requirements unchanged
✅ Date filtering semantics preserved
✅ Currency handling preserved
✅ Calculation formulas preserved