<?php

namespace App\Application\Financial\UseCases;

use App\Models\Booking;
use App\Services\RevenueDistributionService;

/**
 * DistributeBookingRevenueUseCase
 *
 * The single application entry point for "establish the immutable financial
 * snapshot for every booked screen of this booking".
 *
 * WHY THIS IS THE BOUNDARY:
 * Revenue allocation is the hinge between Commercial truth (what the customer
 * agreed to pay) and Settlement truth (what is later payable to providers). It
 * has three distinct callers, each inside a different transaction:
 *
 *   1. `BookingController::storeCampaign()`  — advertiser self-serve booking
 *   2. `ManualBookingService::create()`      — admin manual / ad-partner booking
 *   3. `ReferralTrackingService`             — lazy backfill when commission
 *      attribution finds unsnapshotted booking screens
 *
 * Routing all three through one use case makes the ownership explicit and gives
 * a single seam for future domain extraction, without touching the calculation.
 *
 * BEHAVIOURAL CONTRACT (unchanged — delegated wholesale):
 *  - No-op when the booking has zero booking_screen rows.
 *  - Per-screen gross = round(final_amount / totalScreens, 2), falling back to
 *    subtotal/price when final_amount <= 0 (see RevenueAllocation).
 *  - Illuvision-owned screens: provider columns nulled, 100/0 split,
 *    settlement_status = 'not_applicable'.
 *  - Provider-owned screens: split resolved by the authoritative
 *    RevenueSplitResolverService, settlement_status = 'pending', plus a
 *    `screen_provider_activities` row of type 'revenue_generated'.
 *  - Screen rows that cannot be resolved are skipped silently.
 *  - Wrapped in its own DB::transaction by the legacy service.
 */
class DistributeBookingRevenueUseCase
{
    public function __construct(
        protected RevenueDistributionService $revenueDistributionService
    ) {}

    public function execute(Booking $booking): void
    {
        $this->revenueDistributionService->processBookingScreens($booking);
    }
}
