# Phase 0.20 - File Movement Ledger

**Captured:** 2026-09-26

Every file physically relocated in Phase 0.20. Old paths no longer exist.

| # | From | To | Exists(new) | Batch | Priority |
|---|---|---|---|---|---|
| 1 | `app/Infrastructure/Notification/Contracts/MailContractInterface.php` | `app/Infrastructure/Notifications/Contracts/MailContractInterface.php` | yes | Batch 1 | P0 |
| 2 | `app/Infrastructure/Notification/LaravelMailAdapter.php` | `app/Infrastructure/Notifications/LaravelMailAdapter.php` | yes | Batch 1 | P0 |
| 3 | `app/Infrastructure/Media/Contracts/StorageContractInterface.php` | `app/Infrastructure/Storage/Contracts/StorageContractInterface.php` | yes | Batch 1 | P0 |
| 4 | `app/Infrastructure/Media/LaravelStorageAdapter.php` | `app/Infrastructure/Storage/LaravelStorageAdapter.php` | yes | Batch 1 | P0 |
| 5 | `app/Events/Notifications/BookingApproved.php` | `app/Support/Events/Notifications/BookingApproved.php` | yes | Batch 2a | P0 |
| 6 | `app/Events/Notifications/BookingCancelled.php` | `app/Support/Events/Notifications/BookingCancelled.php` | yes | Batch 2a | P0 |
| 7 | `app/Events/Notifications/BookingCreated.php` | `app/Support/Events/Notifications/BookingCreated.php` | yes | Batch 2a | P0 |
| 8 | `app/Events/Notifications/BookingRejected.php` | `app/Support/Events/Notifications/BookingRejected.php` | yes | Batch 2a | P0 |
| 9 | `app/Events/Notifications/CommissionGenerated.php` | `app/Support/Events/Notifications/CommissionGenerated.php` | yes | Batch 2a | P0 |
| 10 | `app/Events/Notifications/ContactEnquiryAssigned.php` | `app/Support/Events/Notifications/ContactEnquiryAssigned.php` | yes | Batch 2a | P0 |
| 11 | `app/Events/Notifications/ContactEnquiryClosed.php` | `app/Support/Events/Notifications/ContactEnquiryClosed.php` | yes | Batch 2a | P0 |
| 12 | `app/Events/Notifications/ContactEnquirySubmitted.php` | `app/Support/Events/Notifications/ContactEnquirySubmitted.php` | yes | Batch 2a | P0 |
| 13 | `app/Events/Notifications/MediaApproved.php` | `app/Support/Events/Notifications/MediaApproved.php` | yes | Batch 2a | P0 |
| 14 | `app/Events/Notifications/MediaRejected.php` | `app/Support/Events/Notifications/MediaRejected.php` | yes | Batch 2a | P0 |
| 15 | `app/Events/Notifications/MediaUploaded.php` | `app/Support/Events/Notifications/MediaUploaded.php` | yes | Batch 2a | P0 |
| 16 | `app/Events/Notifications/PartnerApproved.php` | `app/Support/Events/Notifications/PartnerApproved.php` | yes | Batch 2a | P0 |
| 17 | `app/Events/Notifications/PartnerCreated.php` | `app/Support/Events/Notifications/PartnerCreated.php` | yes | Batch 2a | P0 |
| 18 | `app/Events/Notifications/PartnerRejected.php` | `app/Support/Events/Notifications/PartnerRejected.php` | yes | Batch 2a | P0 |
| 19 | `app/Events/Notifications/PaymentFailed.php` | `app/Support/Events/Notifications/PaymentFailed.php` | yes | Batch 2a | P0 |
| 20 | `app/Events/Notifications/PaymentReceived.php` | `app/Support/Events/Notifications/PaymentReceived.php` | yes | Batch 2a | P0 |
| 21 | `app/Events/Notifications/PaymentRefunded.php` | `app/Support/Events/Notifications/PaymentRefunded.php` | yes | Batch 2a | P0 |
| 22 | `app/Events/Notifications/ProductEnquirySubmitted.php` | `app/Support/Events/Notifications/ProductEnquirySubmitted.php` | yes | Batch 2a | P0 |
| 23 | `app/Events/Notifications/ProfileUpdated.php` | `app/Support/Events/Notifications/ProfileUpdated.php` | yes | Batch 2a | P0 |
| 24 | `app/Events/Notifications/ReferralUsed.php` | `app/Support/Events/Notifications/ReferralUsed.php` | yes | Batch 2a | P0 |
| 25 | `app/Events/Notifications/RewardsBonusAwarded.php` | `app/Support/Events/Notifications/RewardsBonusAwarded.php` | yes | Batch 2a | P0 |
| 26 | `app/Events/Notifications/RewardsEarned.php` | `app/Support/Events/Notifications/RewardsEarned.php` | yes | Batch 2a | P0 |
| 27 | `app/Events/Notifications/RewardsExpiringSoon.php` | `app/Support/Events/Notifications/RewardsExpiringSoon.php` | yes | Batch 2a | P0 |
| 28 | `app/Events/Notifications/RewardsRedeemed.php` | `app/Support/Events/Notifications/RewardsRedeemed.php` | yes | Batch 2a | P0 |
| 29 | `app/Events/Notifications/ScreenProviderApproved.php` | `app/Support/Events/Notifications/ScreenProviderApproved.php` | yes | Batch 2a | P0 |
| 30 | `app/Events/Notifications/ScreenProviderCreated.php` | `app/Support/Events/Notifications/ScreenProviderCreated.php` | yes | Batch 2a | P0 |
| 31 | `app/Events/Notifications/ScreenProviderEnquirySubmitted.php` | `app/Support/Events/Notifications/ScreenProviderEnquirySubmitted.php` | yes | Batch 2a | P0 |
| 32 | `app/Events/Notifications/ScreenProviderLeadAssigned.php` | `app/Support/Events/Notifications/ScreenProviderLeadAssigned.php` | yes | Batch 2a | P0 |
| 33 | `app/Events/Notifications/ScreenProviderLeadClosed.php` | `app/Support/Events/Notifications/ScreenProviderLeadClosed.php` | yes | Batch 2a | P0 |
| 34 | `app/Events/Notifications/ScreenProviderLeadConverted.php` | `app/Support/Events/Notifications/ScreenProviderLeadConverted.php` | yes | Batch 2a | P0 |
| 35 | `app/Events/Notifications/ScreenProviderPortalInvitation.php` | `app/Support/Events/Notifications/ScreenProviderPortalInvitation.php` | yes | Batch 2a | P0 |
| 36 | `app/Events/Notifications/ScreenProviderRejected.php` | `app/Support/Events/Notifications/ScreenProviderRejected.php` | yes | Batch 2a | P0 |
| 37 | `app/Events/Notifications/UserRegistered.php` | `app/Support/Events/Notifications/UserRegistered.php` | yes | Batch 2a | P0 |
| 38 | `app/Events/AssetCreated.php` | `app/Domain/Asset/Events/AssetCreated.php` | yes | Batch 2a | P0 |
| 39 | `app/Exceptions/FleetCapacityExceededException.php` | `app/Domain/Availability/Exceptions/FleetCapacityExceededException.php` | yes | Batch 2b | P0 |
| 40 | `app/Exceptions/InvalidBookingStateException.php` | `app/Domain/Booking/Exceptions/InvalidBookingStateException.php` | yes | Batch 2b | P0 |
| 41 | `app/Exceptions/PricingException.php` | `app/Domain/Pricing/Exceptions/PricingException.php` | yes | Batch 2b | P0 |
| 42 | `app/Exceptions/UserConflictException.php` | `app/Support/Exceptions/UserConflictException.php` | yes | Batch 2b | P0 |
| 43 | `app/Enums/BookingStatus.php` | `app/Domain/Booking/Enums/BookingStatus.php` | yes | Batch 2b | P0 |
| 44 | `app/Enums/PricingUnit.php` | `app/Domain/Pricing/Enums/PricingUnit.php` | yes | Batch 2b | P0 |
| 45 | `app/Services/ClientService.php` | `app/Services/Campaign/ClientService.php` | yes | Batch 2c | P1 |
| 46 | `app/Services/ClientAnalyticsService.php` | `app/Services/Campaign/ClientAnalyticsService.php` | yes | Batch 2c | P1 |
| 47 | `app/Services/ScreenAnalyticsService.php` | `app/Services/Campaign/ScreenAnalyticsService.php` | yes | Batch 2c | P1 |
| 48 | `app/Services/CurrencyService.php` | `app/Services/Campaign/CurrencyService.php` | yes | Batch 2c | P1 |
| 49 | `app/Services/ExchangeRateService.php` | `app/Services/Campaign/ExchangeRateService.php` | yes | Batch 2c | P1 |
| 50 | `app/Services/ReferralService.php` | `app/Services/Campaign/ReferralService.php` | yes | Batch 2c | P1 |
| 51 | `app/Services/ReferralTrackingService.php` | `app/Services/Campaign/ReferralTrackingService.php` | yes | Batch 2c | P1 |
| 52 | `app/Services/LoyaltyService.php` | `app/Services/Campaign/LoyaltyService.php` | yes | Batch 2c | P1 |
| 53 | `app/Services/RecommendationService.php` | `app/Services/Campaign/RecommendationService.php` | yes | Batch 2c | P1 |
| 54 | `app/Services/BudgetRecommendationService.php` | `app/Services/Campaign/BudgetRecommendationService.php` | yes | Batch 2c | P1 |
| 55 | `app/Services/CountryDetectionService.php` | `app/Services/Campaign/CountryDetectionService.php` | yes | Batch 2c | P1 |
| 56 | `app/Services/BookingAnalyticsService.php` | `app/Services/Booking/BookingAnalyticsService.php` | yes | Batch 2c | P1 |
| 57 | `app/Services/PaymentAnalyticsService.php` | `app/Services/Payment/PaymentAnalyticsService.php` | yes | Batch 2c | P1 |
| 58 | `app/Services/RevenueAnalyticsService.php` | `app/Services/Settlement/RevenueAnalyticsService.php` | yes | Batch 2c | P1 |
| 59 | `app/Repositories/AssetRepositoryInterface.php` | `app/Repositories/Asset/AssetRepositoryInterface.php` | yes | Batch 2d | P0 |
| 60 | `app/Repositories/AssetRepository.php` | `app/Repositories/Asset/AssetRepository.php` | yes | Batch 2d | P0 |
| 61 | `app/Repositories/BookingRepositoryInterface.php` | `app/Repositories/Booking/BookingRepositoryInterface.php` | yes | Batch 2d | P0 |
| 62 | `app/Repositories/BookingRepository.php` | `app/Repositories/Booking/BookingRepository.php` | yes | Batch 2d | P0 |
| 63 | `app/Application/Asset/Services/AssetDiscoveryFilterService.php` | `app/Services/Campaign/AssetDiscoveryFilterService.php` | yes | Batch 2d | P0 |
| 64 | `app/Application/Asset/Services/CoverageService.php` | `app/Services/Campaign/CoverageService.php` | yes | Batch 2d | P0 |

## Files created (new, no predecessor)

| Path | Reason |
|---|---|
| `app/Domain/Campaign/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Media/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Provider/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Partner/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Payment/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Settlement/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Reporting/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/` | target capability directory declared by the plan, intentionally empty |
| `app/Services/Pricing/` | target capability directory declared by the plan, intentionally empty |
| `app/Services/Creative/` | target capability directory declared by the plan, intentionally empty |
| `app/Repositories/Campaign/` | target capability directory declared by the plan, intentionally empty |
| `app/Repositories/Pricing/` | target capability directory declared by the plan, intentionally empty |
| `app/Repositories/Payment/` | target capability directory declared by the plan, intentionally empty |
| `app/Repositories/Creative/` | target capability directory declared by the plan, intentionally empty |
| `app/Infrastructure/Persistence/` | target capability directory declared by the plan, intentionally empty |
| `app/Infrastructure/Payments/` | target capability directory declared by the plan, intentionally empty |
| `app/Infrastructure/ExchangeRates/` | target capability directory declared by the plan, intentionally empty |
| `app/Infrastructure/ExternalServices/` | target capability directory declared by the plan, intentionally empty |
| `app/Support/Jobs/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/Creator/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/Service/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/Brief/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/Proposal/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/Project/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/Revision/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/Delivery/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/Review/` | target capability directory declared by the plan, intentionally empty |
| `app/Domain/Creative/Earnings/` | target capability directory declared by the plan, intentionally empty |

## Files deleted

- 0 files deleted in Phase 0.20. (Phase 0.17 deleted 16 verified-dead classes; those deletions are recorded in the Phase 0.17 artifacts.)

## Files modified without moving

- `app/Providers/AppServiceProvider.php` — 4 container bindings re-pointed.
- `app/Providers/EventServiceProvider.php` — subscriber registration re-pointed.
- `tests/Unit/Architecture/ArchitectureRuleTest.php` — 1 exact-pinned debt string followed a moved class.
- 13 further test files — namespace imports only, no logic change.

## Directories removed once empty

- `app/Events/` — all 34 classes relocated to `Support/Events` and `Domain/Asset/Events`.
- `app/Exceptions/` — all 4 exceptions relocated to their owning domains / `Support/Exceptions`.
- `app/Application/Asset/` — relocated in Batch 2d to `app/Services/Campaign/`.
- `app/Infrastructure/Notification/` — relocated in Batch 1 to `app/Infrastructure/Notifications/`.
