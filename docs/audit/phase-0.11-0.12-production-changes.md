# Phase 0.11–0.12 — Production Changes

## Structural Changes

### New reporting queries
- `app/Application/Reporting/OverviewQuery.php` - Extracted from ReportService::getOverview
- `app/Application/Reporting/RevenueReportQuery.php` - Extracted from ReportService::getRevenueReport
- `app/Application/Reporting/BookingReportQuery.php` - Extracted from ReportService::getBookingReport
- `app/Application/Reporting/ScreenReportQuery.php` - Extracted from ReportService::getScreenReport
- `app/Application/Reporting/ScreenProviderReportQuery.php` - Extracted from ReportService::getScreenProviderReport
- `app/Application/Reporting/CityReportQuery.php` - Extracted from ReportService::getCityReport
- `app/Application/Reporting/CampaignReportQuery.php` - Extracted from ReportService::getCampaignReport
- `app/Application/Reporting/ReferralReportQuery.php` - Extracted from ReportService::getReferralReport
- `app/Application/Reporting/CategoryReportQuery.php` - Extracted from ReportService::getCategoryReport
- `app/Application/Reporting/RewardReportQuery.php` - Extracted from ReportService::getRewardReport

### New application handlers (for integration boundary)
- None identified in current analysis - to be determined during integration boundary work

### New adapters
- None - reusing existing adapters from Phase 0.5

### New contracts
- `app/Domain/Reporting/Contracts/ReportRepositoryInterface.php` - Contract for reporting data access

### Dependency injection changes
- ReportController constructor will change to accept query objects instead of ReportService
- DashboardController may change to accept specific queries instead of analytics services
- PortalController may change to accept specific queries instead of PartnerService

### Controller delegation changes
- ReportController will delegate to reporting query objects instead of ReportService
- DashboardController may delegate to specific query objects
- PortalController unchanged for now (partner reporting is separate)

## Behavioral Changes

### Normally:
NONE

### Documented changes:
None identified - this phase focuses on structural extraction without behavior changes

## Risks and Mitigations

### Risk: API contract changes
- Likelihood: Low
- Impact: High
- Mitigation: Golden contract testing will verify identical outputs

### Risk: Behavioral changes in calculations
- Likelihood: Low
- Impact: High
- Mitigation: Side-by-side comparison of outputs before/after changes

### Risk: Performance degradation
- Likelihood: Medium
- Impact: Medium
- Mitigation: Verify query efficiency, ensure proper indexing

### Risk: Missing dependencies
- Likelihood: Low
- Impact: Low
- Mitigation: Comprehensive dependency mapping completed in analysis

## Smoke Checks

### Post-extraction verification:
1. All reporting endpoints return 200 status for valid requests
2. Response JSON structure matches pre-extraction exactly
3. No new database errors appear in logs
4. Authentication and authorization requirements unchanged
5. Rate limiting and caching behavior unchanged

## Rollback Plan

### If issues detected:
1. Rollback code changes to pre-extraction state
2. Verify API contracts still pass
3. Investigate and resolve issues before re-attempting

### Rollback indicators:
1. Any reporting endpoint returns non-200 status for valid request
2. Response JSON structure differs from pre-extraction
3. Database errors appear in logs
4. Authentication/authorization behavior changes