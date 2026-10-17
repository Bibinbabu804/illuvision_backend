# Phase 0.12 — Integration Architecture Diff

## Current State (Before Enforcement)

```
HTTP Layer
    ↓
Controllers/Services
    ↓
[Mixed: Some use interfaces, some use direct external calls, some use facades]
    ↓
External Services (Razorpay, Google, Mail, Storage, etc.) 
    ↓
External APIs
```

### Issues with Current State:
1. **Inconsistent boundary enforcement** - Some services properly use interfaces, others use direct calls or facades
2. **Facade leakage** - Direct usage of Mail::, Storage::, HTTP:: facades in application layer
3. **Potential direct library usage** - Possible direct Google\\Client, AWS SDK, Intervention Image usage
4. **Inconsistent adapter implementation** - Some adapters may not fully implement interfaces or may leak concerns

## Target State (After Enforcement)

```
HTTP Layer
    ↓
Controllers/Services
    ↓
Application Layer (Use Cases)
    ↓
Integration Contracts (Interfaces)
    ↓
Infrastructure Layer (Adapters)
    ↓
External Services
```

### Specific Changes:
1. **Enforce dependency inversion** - All application services must depend on interfaces, not concrete implementations
2. **Eliminate facade usage** - Replace direct Mail::, Storage::, HTTP:: calls with interface-based dependencies
3. **Verify adapter implementations** - Ensure all infrastructure adapters properly implement their contracts
4. **Standardize external service wrapping** - All external service calls must go through adapters

## Boundary Diagrams

### Before (Mixed State):
```
Controller/Service
    ↓
[Some use interfaces → Adapter → External Service]
[Some use facades → Direct External Service Calls]
[Some use direct library calls → External Service]
```

### After (Consistent Boundary):
```
Controller/Service
    ↓
Application Layer (Use Case)
    ↓
Integration Contract Interface
    ↓
Infrastructure Adapter Implementation
    ↓
External Service API
```

## Rule Compliance Table

| Rule | Current State | Target State | Status |
|------|---------------|--------------|---------|
| No direct external calls in domain layer | ✓ (assumed) | ✓ | LIKELY_COMPLIANT |
| No direct external calls in application layer | ○ | ● | TO_BE_ENFORCED |
| Application layer depends on interfaces, not implementations | ○ | ● | TO_BE_ENFORCED |
| Infrastructure layer implements interfaces | ○ | ● | TO_BE_VERIFIED |
| No framework leaks into domain layer | ✓ | ✓ | LIKELY_COMPLIANT |
| External service calls are replaceable | ○ | ● | TO_BE_VERIFIED |

## Migration Approach

1. **Identify violations** - Scan app/Domain and app/Application for direct external service calls
2. **Define missing interfaces** - Ensure all required integration contracts exist
3. **Update service dependencies** - Change constructors to depend on interfaces
4. **Inject adapters** - Ensure adapters are properly bound in service container
5. **Remove direct calls** - Replace direct external service usage with interface method calls
6. **Verify adapters** - Ensure all adapter implementations are complete and correct
7. **Add tests** - Verify that external service calls work through the boundary

## Files That Will Be Created/Updated

### Interfaces to Verify/Create:
- `app/Domain/Auth/Contracts/GoogleIdentityProviderInterface.php`
- `app/Domain/Financial/Contracts/ExchangeRateApiClientInterface.php`
- `app/Domain/Notification/Contracts/MailContractInterface.php`
- `app/Domain/Notification/Contracts/SmsContractInterface.php`
- `app/Domain/Media/Contracts/StorageContractInterface.php`
- `app/Domain/Media/Contracts/ImageProcessingContractInterface.php`

### Services to Update:
- `App\\Services\\GoogleAuthService` - depend on GoogleIdentityProviderInterface
- `App\\Services\\ExchangeRateService` - depend on ExchangeRateApiClientInterface
- `App\\Services\\MailService` - depend on MailContractInterface
- `App\\Services\\NotificationService` - depend on notification contracts
- `App\\Services\\MediaHelper` - depend on storage and image processing contracts
- `App\\Services\\UploadService` - depend on StorageContractInterface
- `App\\Services\\PartnerService` - depend` - depend on StorageContractInterface (for uploads)

### Adapter Locations (should already exist from Phase 0.5):
- `App\\Infrastructure\\Auth\\Google\\GoogleOAuthAdapter.php`
- `App\\Infrastructure\\ExchangeRates\\ExchangeRateApiClient.php`
- `App\\Infrastructure\\Mail\\MailAdapter.php`
- `App\\Infrastructure\\Sms\\TwilioAdapter.php`
- `App\\Infrastructure\\Storage\\LocalAdapter.php`
- `App\\Infrastructure\\Storage\\S3Adapter.php`
- `App\\Infrastructure\\ImageProcessing\\GDAdapter.php`
- `App\\Infrastructure\\ImageProcessing\\InterventionAdapter.php`

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Missing interfaces | Low | Medium | Create missing interfaces based on actual usage patterns |
| Breaking changes | Low | High | Maintain backward compatibility during transition |
| Incorrect adapter implementation | Medium | Medium | Test adapters with mock external services |
| Configuration issues | Low | Low | Verify environment variables and config files |
| Facade usage missed in scan | Medium | Medium | Use multiple scanning techniques (grep, static analysis) |

## Compatibility Guarantees

✅ External service behavior will be preserved
✅ API contracts involving webhooks will be unchanged
✅ Authentication flows will work identically
✅ File storage paths and URLs will remain the same
✅ Image processing behavior will be preserved
✅ Email/SMS delivery mechanisms will work identically
✅ Error handling and retry behavior will be preserved
✅ Logging and auditing will continue to function