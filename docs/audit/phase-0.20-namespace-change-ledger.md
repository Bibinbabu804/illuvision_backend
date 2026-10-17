# Phase 0.20 - Namespace Change Ledger

**Captured:** 2026-09-26

**Total classes relocated:** 64

Every row is a namespace change with a corresponding import rewrite in each consumer.

## Ledger

| # | From | To | Old file | New file | Batch |
|---|---|---|---|---|---|
| 1 | `App\Infrastructure\Notifications\Contracts\MailContractInterface` | `App\Infrastructure\Notifications\Contracts\MailContractInterface` | `app/Infrastructure/Notification/Contracts/MailContractInterface.php` | `app/Infrastructure/Notifications/Contracts/MailContractInterface.php` | Batch 1 |
| 2 | `App\Infrastructure\Notifications\LaravelMailAdapter` | `App\Infrastructure\Notifications\LaravelMailAdapter` | `app/Infrastructure/Notification/LaravelMailAdapter.php` | `app/Infrastructure/Notifications/LaravelMailAdapter.php` | Batch 1 |
| 3 | `App\Infrastructure\Storage\Contracts\StorageContractInterface` | `App\Infrastructure\Storage\Contracts\StorageContractInterface` | `app/Infrastructure/Media/Contracts/StorageContractInterface.php` | `app/Infrastructure/Storage/Contracts/StorageContractInterface.php` | Batch 1 |
| 4 | `App\Infrastructure\Storage\LaravelStorageAdapter` | `App\Infrastructure\Storage\LaravelStorageAdapter` | `app/Infrastructure/Media/LaravelStorageAdapter.php` | `app/Infrastructure/Storage/LaravelStorageAdapter.php` | Batch 1 |
| 5 | `App\Support\Events\Notifications\BookingApproved` | `App\Support\Events\Notifications\BookingApproved` | `app/Events/Notifications/BookingApproved.php` | `app/Support/Events/Notifications/BookingApproved.php` | Batch 2a |
| 6 | `App\Support\Events\Notifications\BookingCancelled` | `App\Support\Events\Notifications\BookingCancelled` | `app/Events/Notifications/BookingCancelled.php` | `app/Support/Events/Notifications/BookingCancelled.php` | Batch 2a |
| 7 | `App\Support\Events\Notifications\BookingCreated` | `App\Support\Events\Notifications\BookingCreated` | `app/Events/Notifications/BookingCreated.php` | `app/Support/Events/Notifications/BookingCreated.php` | Batch 2a |
| 8 | `App\Support\Events\Notifications\BookingRejected` | `App\Support\Events\Notifications\BookingRejected` | `app/Events/Notifications/BookingRejected.php` | `app/Support/Events/Notifications/BookingRejected.php` | Batch 2a |
| 9 | `App\Support\Events\Notifications\CommissionGenerated` | `App\Support\Events\Notifications\CommissionGenerated` | `app/Events/Notifications/CommissionGenerated.php` | `app/Support/Events/Notifications/CommissionGenerated.php` | Batch 2a |
| 10 | `App\Support\Events\Notifications\ContactEnquiryAssigned` | `App\Support\Events\Notifications\ContactEnquiryAssigned` | `app/Events/Notifications/ContactEnquiryAssigned.php` | `app/Support/Events/Notifications/ContactEnquiryAssigned.php` | Batch 2a |
| 11 | `App\Support\Events\Notifications\ContactEnquiryClosed` | `App\Support\Events\Notifications\ContactEnquiryClosed` | `app/Events/Notifications/ContactEnquiryClosed.php` | `app/Support/Events/Notifications/ContactEnquiryClosed.php` | Batch 2a |
| 12 | `App\Support\Events\Notifications\ContactEnquirySubmitted` | `App\Support\Events\Notifications\ContactEnquirySubmitted` | `app/Events/Notifications/ContactEnquirySubmitted.php` | `app/Support/Events/Notifications/ContactEnquirySubmitted.php` | Batch 2a |
| 13 | `App\Support\Events\Notifications\MediaApproved` | `App\Support\Events\Notifications\MediaApproved` | `app/Events/Notifications/MediaApproved.php` | `app/Support/Events/Notifications/MediaApproved.php` | Batch 2a |
| 14 | `App\Support\Events\Notifications\MediaRejected` | `App\Support\Events\Notifications\MediaRejected` | `app/Events/Notifications/MediaRejected.php` | `app/Support/Events/Notifications/MediaRejected.php` | Batch 2a |
| 15 | `App\Support\Events\Notifications\MediaUploaded` | `App\Support\Events\Notifications\MediaUploaded` | `app/Events/Notifications/MediaUploaded.php` | `app/Support/Events/Notifications/MediaUploaded.php` | Batch 2a |
| 16 | `App\Support\Events\Notifications\PartnerApproved` | `App\Support\Events\Notifications\PartnerApproved` | `app/Events/Notifications/PartnerApproved.php` | `app/Support/Events/Notifications/PartnerApproved.php` | Batch 2a |
| 17 | `App\Support\Events\Notifications\PartnerCreated` | `App\Support\Events\Notifications\PartnerCreated` | `app/Events/Notifications/PartnerCreated.php` | `app/Support/Events/Notifications/PartnerCreated.php` | Batch 2a |
| 18 | `App\Support\Events\Notifications\PartnerRejected` | `App\Support\Events\Notifications\PartnerRejected` | `app/Events/Notifications/PartnerRejected.php` | `app/Support/Events/Notifications/PartnerRejected.php` | Batch 2a |
| 19 | `App\Support\Events\Notifications\PaymentFailed` | `App\Support\Events\Notifications\PaymentFailed` | `app/Events/Notifications/PaymentFailed.php` | `app/Support/Events/Notifications/PaymentFailed.php` | Batch 2a |
| 20 | `App\Support\Events\Notifications\PaymentReceived` | `App\Support\Events\Notifications\PaymentReceived` | `app/Events/Notifications/PaymentReceived.php` | `app/Support/Events/Notifications/PaymentReceived.php` | Batch 2a |
| 21 | `App\Support\Events\Notifications\PaymentRefunded` | `App\Support\Events\Notifications\PaymentRefunded` | `app/Events/Notifications/PaymentRefunded.php` | `app/Support/Events/Notifications/PaymentRefunded.php` | Batch 2a |
| 22 | `App\Support\Events\Notifications\ProductEnquirySubmitted` | `App\Support\Events\Notifications\ProductEnquirySubmitted` | `app/Events/Notifications/ProductEnquirySubmitted.php` | `app/Support/Events/Notifications/ProductEnquirySubmitted.php` | Batch 2a |
| 23 | `App\Support\Events\Notifications\ProfileUpdated` | `App\Support\Events\Notifications\ProfileUpdated` | `app/Events/Notifications/ProfileUpdated.php` | `app/Support/Events/Notifications/ProfileUpdated.php` | Batch 2a |
| 24 | `App\Support\Events\Notifications\ReferralUsed` | `App\Support\Events\Notifications\ReferralUsed` | `app/Events/Notifications/ReferralUsed.php` | `app/Support/Events/Notifications/ReferralUsed.php` | Batch 2a |
| 25 | `App\Support\Events\Notifications\RewardsBonusAwarded` | `App\Support\Events\Notifications\RewardsBonusAwarded` | `app/Events/Notifications/RewardsBonusAwarded.php` | `app/Support/Events/Notifications/RewardsBonusAwarded.php` | Batch 2a |
| 26 | `App\Support\Events\Notifications\RewardsEarned` | `App\Support\Events\Notifications\RewardsEarned` | `app/Events/Notifications/RewardsEarned.php` | `app/Support/Events/Notifications/RewardsEarned.php` | Batch 2a |
| 27 | `App\Support\Events\Notifications\RewardsExpiringSoon` | `App\Support\Events\Notifications\RewardsExpiringSoon` | `app/Events/Notifications/RewardsExpiringSoon.php` | `app/Support/Events/Notifications/RewardsExpiringSoon.php` | Batch 2a |
| 28 | `App\Support\Events\Notifications\RewardsRedeemed` | `App\Support\Events\Notifications\RewardsRedeemed` | `app/Events/Notifications/RewardsRedeemed.php` | `app/Support/Events/Notifications/RewardsRedeemed.php` | Batch 2a |
| 29 | `App\Support\Events\Notifications\ScreenProviderApproved` | `App\Support\Events\Notifications\ScreenProviderApproved` | `app/Events/Notifications/ScreenProviderApproved.php` | `app/Support/Events/Notifications/ScreenProviderApproved.php` | Batch 2a |
| 30 | `App\Support\Events\Notifications\ScreenProviderCreated` | `App\Support\Events\Notifications\ScreenProviderCreated` | `app/Events/Notifications/ScreenProviderCreated.php` | `app/Support/Events/Notifications/ScreenProviderCreated.php` | Batch 2a |
| 31 | `App\Support\Events\Notifications\ScreenProviderEnquirySubmitted` | `App\Support\Events\Notifications\ScreenProviderEnquirySubmitted` | `app/Events/Notifications/ScreenProviderEnquirySubmitted.php` | `app/Support/Events/Notifications/ScreenProviderEnquirySubmitted.php` | Batch 2a |
| 32 | `App\Support\Events\Notifications\ScreenProviderLeadAssigned` | `App\Support\Events\Notifications\ScreenProviderLeadAssigned` | `app/Events/Notifications/ScreenProviderLeadAssigned.php` | `app/Support/Events/Notifications/ScreenProviderLeadAssigned.php` | Batch 2a |
| 33 | `App\Support\Events\Notifications\ScreenProviderLeadClosed` | `App\Support\Events\Notifications\ScreenProviderLeadClosed` | `app/Events/Notifications/ScreenProviderLeadClosed.php` | `app/Support/Events/Notifications/ScreenProviderLeadClosed.php` | Batch 2a |
| 34 | `App\Support\Events\Notifications\ScreenProviderLeadConverted` | `App\Support\Events\Notifications\ScreenProviderLeadConverted` | `app/Events/Notifications/ScreenProviderLeadConverted.php` | `app/Support/Events/Notifications/ScreenProviderLeadConverted.php` | Batch 2a |
| 35 | `App\Support\Events\Notifications\ScreenProviderPortalInvitation` | `App\Support\Events\Notifications\ScreenProviderPortalInvitation` | `app/Events/Notifications/ScreenProviderPortalInvitation.php` | `app/Support/Events/Notifications/ScreenProviderPortalInvitation.php` | Batch 2a |
| 36 | `App\Support\Events\Notifications\ScreenProviderRejected` | `App\Support\Events\Notifications\ScreenProviderRejected` | `app/Events/Notifications/ScreenProviderRejected.php` | `app/Support/Events/Notifications/ScreenProviderRejected.php` | Batch 2a |
| 37 | `App\Support\Events\Notifications\UserRegistered` | `App\Support\Events\Notifications\UserRegistered` | `app/Events/Notifications/UserRegistered.php` | `app/Support/Events/Notifications/UserRegistered.php` | Batch 2a |
| 38 | `App\Domain\Asset\Events\AssetCreated` | `App\Domain\Asset\Events\AssetCreated` | `app/Events/AssetCreated.php` | `app/Domain/Asset/Events/AssetCreated.php` | Batch 2a |
| 39 | `App\Domain\Availability\Exceptions\FleetCapacityExceededException` | `App\Domain\Availability\Exceptions\FleetCapacityExceededException` | `app/Exceptions/FleetCapacityExceededException.php` | `app/Domain/Availability/Exceptions/FleetCapacityExceededException.php` | Batch 2b |
| 40 | `App\Domain\Booking\Exceptions\InvalidBookingStateException` | `App\Domain\Booking\Exceptions\InvalidBookingStateException` | `app/Exceptions/InvalidBookingStateException.php` | `app/Domain/Booking/Exceptions/InvalidBookingStateException.php` | Batch 2b |
| 41 | `App\Domain\Pricing\Exceptions\PricingException` | `App\Domain\Pricing\Exceptions\PricingException` | `app/Exceptions/PricingException.php` | `app/Domain/Pricing/Exceptions/PricingException.php` | Batch 2b |
| 42 | `App\Support\Exceptions\UserConflictException` | `App\Support\Exceptions\UserConflictException` | `app/Exceptions/UserConflictException.php` | `app/Support/Exceptions/UserConflictException.php` | Batch 2b |
| 43 | `App\Domain\Booking\Enums\BookingStatus` | `App\Domain\Booking\Enums\BookingStatus` | `app/Enums/BookingStatus.php` | `app/Domain/Booking/Enums/BookingStatus.php` | Batch 2b |
| 44 | `App\Domain\Pricing\Enums\PricingUnit` | `App\Domain\Pricing\Enums\PricingUnit` | `app/Enums/PricingUnit.php` | `app/Domain/Pricing/Enums/PricingUnit.php` | Batch 2b |
| 45 | `App\Services\Campaign\ClientService` | `App\Services\Campaign\ClientService` | `app/Services/ClientService.php` | `app/Services/Campaign/ClientService.php` | Batch 2c |
| 46 | `App\Services\Campaign\ClientAnalyticsService` | `App\Services\Campaign\ClientAnalyticsService` | `app/Services/ClientAnalyticsService.php` | `app/Services/Campaign/ClientAnalyticsService.php` | Batch 2c |
| 47 | `App\Services\Campaign\ScreenAnalyticsService` | `App\Services\Campaign\ScreenAnalyticsService` | `app/Services/ScreenAnalyticsService.php` | `app/Services/Campaign/ScreenAnalyticsService.php` | Batch 2c |
| 48 | `App\Services\Campaign\CurrencyService` | `App\Services\Campaign\CurrencyService` | `app/Services/CurrencyService.php` | `app/Services/Campaign/CurrencyService.php` | Batch 2c |
| 49 | `App\Services\Campaign\ExchangeRateService` | `App\Services\Campaign\ExchangeRateService` | `app/Services/ExchangeRateService.php` | `app/Services/Campaign/ExchangeRateService.php` | Batch 2c |
| 50 | `App\Services\Campaign\ReferralService` | `App\Services\Campaign\ReferralService` | `app/Services/ReferralService.php` | `app/Services/Campaign/ReferralService.php` | Batch 2c |
| 51 | `App\Services\Campaign\ReferralTrackingService` | `App\Services\Campaign\ReferralTrackingService` | `app/Services/ReferralTrackingService.php` | `app/Services/Campaign/ReferralTrackingService.php` | Batch 2c |
| 52 | `App\Services\Campaign\LoyaltyService` | `App\Services\Campaign\LoyaltyService` | `app/Services/LoyaltyService.php` | `app/Services/Campaign/LoyaltyService.php` | Batch 2c |
| 53 | `App\Services\Campaign\RecommendationService` | `App\Services\Campaign\RecommendationService` | `app/Services/RecommendationService.php` | `app/Services/Campaign/RecommendationService.php` | Batch 2c |
| 54 | `App\Services\Campaign\BudgetRecommendationService` | `App\Services\Campaign\BudgetRecommendationService` | `app/Services/BudgetRecommendationService.php` | `app/Services/Campaign/BudgetRecommendationService.php` | Batch 2c |
| 55 | `App\Services\Campaign\CountryDetectionService` | `App\Services\Campaign\CountryDetectionService` | `app/Services/CountryDetectionService.php` | `app/Services/Campaign/CountryDetectionService.php` | Batch 2c |
| 56 | `App\Services\Booking\BookingAnalyticsService` | `App\Services\Booking\BookingAnalyticsService` | `app/Services/BookingAnalyticsService.php` | `app/Services/Booking/BookingAnalyticsService.php` | Batch 2c |
| 57 | `App\Services\Payment\PaymentAnalyticsService` | `App\Services\Payment\PaymentAnalyticsService` | `app/Services/PaymentAnalyticsService.php` | `app/Services/Payment/PaymentAnalyticsService.php` | Batch 2c |
| 58 | `App\Services\Settlement\RevenueAnalyticsService` | `App\Services\Settlement\RevenueAnalyticsService` | `app/Services/RevenueAnalyticsService.php` | `app/Services/Settlement/RevenueAnalyticsService.php` | Batch 2c |
| 59 | `App\Repositories\Asset\AssetRepositoryInterface` | `App\Repositories\Asset\AssetRepositoryInterface` | `app/Repositories/AssetRepositoryInterface.php` | `app/Repositories/Asset/AssetRepositoryInterface.php` | Batch 2d |
| 60 | `App\Repositories\Asset\AssetRepository` | `App\Repositories\Asset\AssetRepository` | `app/Repositories/AssetRepository.php` | `app/Repositories/Asset/AssetRepository.php` | Batch 2d |
| 61 | `App\Repositories\Booking\BookingRepositoryInterface` | `App\Repositories\Booking\BookingRepositoryInterface` | `app/Repositories/BookingRepositoryInterface.php` | `app/Repositories/Booking/BookingRepositoryInterface.php` | Batch 2d |
| 62 | `App\Repositories\Booking\BookingRepository` | `App\Repositories\Booking\BookingRepository` | `app/Repositories/BookingRepository.php` | `app/Repositories/Booking/BookingRepository.php` | Batch 2d |
| 63 | `App\Services\Campaign\AssetDiscoveryFilterService` | `App\Services\Campaign\AssetDiscoveryFilterService` | `app/Application/Asset/Services/AssetDiscoveryFilterService.php` | `app/Services/Campaign/AssetDiscoveryFilterService.php` | Batch 2d |
| 64 | `App\Services\Campaign\CoverageService` | `App\Services\Campaign\CoverageService` | `app/Application/Asset/Services/CoverageService.php` | `app/Services/Campaign/CoverageService.php` | Batch 2d |

## Namespace consolidations

| Old namespace | New namespace | Classes |
|---|---|---|
| `App\Application\Asset\Services` | `App\Services\Campaign` | 2 |
| `App\Enums` | `App\Domain\Booking\Enums` | 1 |
| `App\Enums` | `App\Domain\Pricing\Enums` | 1 |
| `App\Events` | `App\Domain\Asset\Events` | 1 |
| `App\Events\Notifications` | `App\Support\Events\Notifications` | 33 |
| `App\Exceptions` | `App\Domain\Availability\Exceptions` | 1 |
| `App\Exceptions` | `App\Domain\Booking\Exceptions` | 1 |
| `App\Exceptions` | `App\Domain\Pricing\Exceptions` | 1 |
| `App\Exceptions` | `App\Support\Exceptions` | 1 |
| `App\Infrastructure\Media` | `App\Infrastructure\Storage` | 1 |
| `App\Infrastructure\Media\Contracts` | `App\Infrastructure\Storage\Contracts` | 1 |
| `App\Infrastructure\Notification` | `App\Infrastructure\Notifications` | 1 |
| `App\Infrastructure\Notification\Contracts` | `App\Infrastructure\Notifications\Contracts` | 1 |
| `App\Repositories` | `App\Repositories\Asset` | 2 |
| `App\Repositories` | `App\Repositories\Booking` | 2 |
| `App\Services` | `App\Services\Booking` | 1 |
| `App\Services` | `App\Services\Campaign` | 11 |
| `App\Services` | `App\Services\Payment` | 1 |
| `App\Services` | `App\Services\Settlement` | 1 |

## Namespaces eliminated (no remaining members)

- `App\Application\Asset\Services` -> fully absorbed into `App\Services\Campaign` (2 classes)
- `App\Events` -> fully absorbed into `App\Domain\Asset\Events` (1 classes)
- `App\Events\Notifications` -> fully absorbed into `App\Support\Events\Notifications` (33 classes)
- `App\Exceptions` -> fully absorbed into `App\Domain\Availability\Exceptions` (1 classes)
- `App\Exceptions` -> fully absorbed into `App\Domain\Booking\Exceptions` (1 classes)
- `App\Exceptions` -> fully absorbed into `App\Domain\Pricing\Exceptions` (1 classes)
- `App\Exceptions` -> fully absorbed into `App\Support\Exceptions` (1 classes)
- `App\Infrastructure\Notification` -> fully absorbed into `App\Infrastructure\Notifications` (1 classes)
- `App\Infrastructure\Notification\Contracts` -> fully absorbed into `App\Infrastructure\Notifications\Contracts` (1 classes)
- `App\Repositories` -> fully absorbed into `App\Repositories\Asset` (2 classes)
- `App\Repositories` -> fully absorbed into `App\Repositories\Booking` (2 classes)
