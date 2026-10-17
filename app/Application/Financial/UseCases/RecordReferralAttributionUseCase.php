<?php

namespace App\Application\Financial\UseCases;

use App\Models\Booking;
use App\Models\ReferralCode;
use App\Services\Campaign\ReferralTrackingService;
use App\Support\Events\Notifications\CommissionGenerated;
use App\Support\Events\Notifications\ReferralUsed;
use App\Services\Campaign\ReferralService;

/**
 * RecordReferralAttributionUseCase
 *
 * Application entry point for the referral-attribution / partner-commission
 * operation that runs after money is confirmed for a booking.
 *
 * WHY THIS IS THE BOUNDARY (Phase 0.10, section 19):
 * Attribution, commission calculation, commission persistence and commission
 * settlement are currently coupled inside `ReferralTrackingService`. They are
 * four distinct responsibilities with different owners:
 *
 *   referral attribution        -> ReferralTrackingService (unchanged)
 *   commission calculation      -> ReferralService::calculateCommission (unchanged)
 *   commission persistence      -> PartnerCommissionRepositoryInterface (Phase 0.10)
 *   commission settlement       -> CommissionService (unchanged, pessively locked)
 *
 * This use case owns the *orchestration* of the first two and is the single
 * shared entry point for all three live callers of the operation:
 *
 *   1. `PaymentService::verifyAndConfirmPayment()` — gateway payment verified
 *   2. `ManualBookingService::recordOfflinePayment()` — offline payment recorded
 *   3. `AdminPaymentController::approveDirectPayment()` — direct payment approved
 *   4. `ManualBookingService::create()` — booking created with offline payment
 *
 * BEHAVIOURAL CONTRACT (unchanged — delegated wholesale):
 *  - Null referral code -> `['usage' => null, 'commission' => null]`, no writes.
 *  - Duplicate guard: if a `referral_usages` row already exists for the booking,
 *    the existing usage and its commission are returned and NOTHING is created.
 *  - Lazy revenue backfill: if booking screens are missing platform_share, the
 *    revenue distribution use case runs first, so commission base is correct.
 *  - Commission base is the sum of `platform_share` (Illuvision revenue), not the
 *    booking total. Zero booking screens fall back to 100% Illuvision revenue.
 *  - `referral_codes.used_count` is incremented as a side effect.
 *  - `ReferralUsed` and `CommissionGenerated` are dispatched INSIDE the caller's
 *    transaction, i.e. before commit. Not normalised in this phase.
 *  - The caller remains responsible for the surrounding transaction.
 */
class RecordReferralAttributionUseCase
{
    public function __construct(
        protected ReferralTrackingService $referralTrackingService
    ) {}

    /**
     * @return array{usage: \App\Models\ReferralUsage|null, commission: \App\Models\PartnerCommission|null}
     */
    public function execute(Booking $booking, int $paymentId, ?ReferralCode $referralCode = null): array
    {
        return $this->referralTrackingService->createReferralTracking(
            $booking,
            $paymentId,
            $referralCode
        );
    }
}
