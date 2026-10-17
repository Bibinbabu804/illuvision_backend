<?php

namespace App\Services\Campaign;

use App\Domain\Financial\Contracts\PartnerCommissionRepositoryInterface;
use App\Application\Financial\UseCases\DistributeBookingRevenueUseCase;
use App\Support\Events\Notifications\ReferralUsed;
use App\Models\Booking;
use App\Models\ReferralCode;
use App\Models\ReferralUsage;
use App\Models\PartnerCommission;
use App\Services\Campaign\ReferralService;

/**
 * ReferralTrackingService
 *
 * Orchestrates referral usage creation and partner commission generation
 * AFTER successful payment verification.
 *
 * NOTE: This service does NOT manage its own database transactions.
 * The caller (PaymentService) is responsible for wrapping calls in
 * DB::transaction() to ensure atomicity.
 *
 * Business flow:
 *   Payment Verified -> Referral Usage Created -> Partner Commission Created
 *                    -> Booking moves to Pending Approval
 *
 * PHASE 0.10 STRUCTURAL CHANGE (behaviour-preserving):
 *  - Commission persistence now goes through
 *    `PartnerCommissionRepositoryInterface`, separating referral attribution
 *    from commission storage. The attribution write path deliberately takes NO
 *    row lock (as before) — the commission lifecycle lock lives in
 *    `CommissionService`.
 *  - The lazy revenue backfill now calls `DistributeBookingRevenueUseCase`
 *    instead of resolving the service out of the container by class name, so
 *    the dependency is explicit. The same code path executes.
 *  - No attribution rule, duplicate guard, commission formula or event timing
 *    was changed.
 */
class ReferralTrackingService
{
    public function __construct(
        protected ReferralService $referralService,
        protected PartnerCommissionRepositoryInterface $commissionRepository,
        protected DistributeBookingRevenueUseCase $distributeBookingRevenue,
    ) {}

    /**
     * Create referral usage and partner commission after successful payment.
     *
     * Caller MUST be inside a DB::transaction.
     * Guards against duplicate referral_usages for the same booking.
     */
    public function createReferralTracking(Booking $booking, int $paymentId, ?ReferralCode $referralCode): array
    {
        if (!$referralCode) {
            return ['usage' => null, 'commission' => null];
        }

        // Guard: prevent duplicate referral_usages for this booking
        $existingUsage = ReferralUsage::where('booking_id', $booking->id)->first();
        if ($existingUsage) {
            $existingCommission = $this->commissionRepository->findForReferralUsage($existingUsage->id);
            return ['usage' => $existingUsage, 'commission' => $existingCommission];
        }

        // 1. Ensure revenue distribution snapshots are processed for booking screens
        $bookingScreens = $booking->bookingScreens;
        if ($bookingScreens->isEmpty() || $bookingScreens->first()->platform_share === null) {
            $this->distributeBookingRevenue->execute($booking);
            $booking = $booking->fresh(['bookingScreens']);
            $bookingScreens = $booking->bookingScreens;
        }

        // 2. Aggregate financial metrics across all booked assets independently
        $campaignTotal = (float) ($booking->final_amount ?? $booking->price ?? $booking->subtotal ?? 0);
        $providerRevenue = (float) $bookingScreens->sum('provider_share');
        $illuvisionRevenue = (float) $bookingScreens->sum('platform_share');

        // Fallback: If no booking screens exist, 100% of revenue belongs to Illuvision
        if ($bookingScreens->isEmpty()) {
            $illuvisionRevenue = $campaignTotal;
            $providerRevenue = 0.0;
        }

        // 3. Sprint B1 Profit-Based Commission Base: Partner commission is calculated strictly from Illuvision Revenue
        $partnerCommissionBase = round($illuvisionRevenue, 2);
        $commissionPercentage = $referralCode->commission_type === 'percentage'
            ? (float) $referralCode->commission_value
            : 0.0;

        $subtotal = $booking->subtotal ?? 0;

        $discountAmount = $this->referralService->calculateDiscount(
            $referralCode,
            $subtotal
        );

        $commissionAmount = $this->referralService->calculateCommission(
            $referralCode,
            $partnerCommissionBase
        );

        $commissionSnapshots = [
            'campaign_total' => $campaignTotal,
            'provider_revenue' => $providerRevenue,
            'illuvision_revenue' => $illuvisionRevenue,
            'partner_commission_base' => $partnerCommissionBase,
            'partner_commission_percentage' => $commissionPercentage,
        ];

        // 4. Create referral usage record with snapshots
        $usage = $this->referralService->createUsage(
            $referralCode,
            $booking->id,
            $booking->user_id,
            $paymentId,
            $referralCode->partner_id,
            $discountAmount,
            $commissionAmount,
            $campaignTotal,
            $commissionSnapshots
        );

        $partnerCommission = null;
        // 5. Create partner commission record with full financial transparency snapshots
        if ($commissionAmount > 0 && $referralCode->partner_id) {
            $partnerCommission = $this->commissionRepository->create([
                'partner_id' => $referralCode->partner_id,
                'referral_usage_id' => $usage->id,
                'booking_id' => $booking->id,
                'payment_id' => $paymentId,
                'commission_type' => $referralCode->commission_type ?? 'percentage',
                'commission_value' => $referralCode->commission_value ?? 0,
                'campaign_total' => $campaignTotal,
                'provider_revenue' => $providerRevenue,
                'illuvision_revenue' => $illuvisionRevenue,
                'partner_commission_base' => $partnerCommissionBase,
                'partner_commission_percentage' => $commissionPercentage,
                'amount' => $commissionAmount,
                'status' => PartnerCommission::STATUS_PENDING,
                'generated_at' => now(),
                'notes' => "Profit-based commission calculated on Illuvision Revenue (₹" . number_format($partnerCommissionBase, 2) . ") for booking #{$booking->id}",
            ]);
        }
        
        // Dispatch notification events
        if ($usage) {
            $usage->load(['partner', 'booking', 'referralCode']);
            event(new ReferralUsed($usage));
        }

        if ($partnerCommission) {
            $partnerCommission->load(['partner', 'referralUsage.referralCode']);
            event(new \App\Support\Events\Notifications\CommissionGenerated($partnerCommission));
        }

        return ['usage' => $usage, 'commission' => $partnerCommission];
    }

    /**
     * Mark referral tracking as cancelled.
     * Handles both: pending_payment usages (payment failed before creation)
     *               and confirmed usages (admin rejected booking after payment).
     */
    public function cancelReferralTracking(Booking $booking): void
    {
        $usages = ReferralUsage::where('booking_id', $booking->id)
            ->whereIn('status', [
                ReferralUsage::STATUS_PENDING_PAYMENT,
                ReferralUsage::STATUS_CONFIRMED,
            ])
            ->get();

        foreach ($usages as $usage) {
            $usage->update([
                'status' => ReferralUsage::STATUS_CANCELLED,
                'used_at' => now(),
            ]);

            // Cancel associated pending commissions
            PartnerCommission::where('referral_usage_id', $usage->id)
                ->where('status', PartnerCommission::STATUS_PENDING)
                ->update(['status' => PartnerCommission::STATUS_CANCELLED]);
        }
    }

    /**
     * Mark referral tracking as refunded (for refunded payments).
     */
    public function refundReferralTracking(Booking $booking): void
    {
        $usages = ReferralUsage::where('booking_id', $booking->id)
            ->where('status', ReferralUsage::STATUS_CONFIRMED)
            ->get();

        foreach ($usages as $usage) {
            $usage->update([
                'status' => ReferralUsage::STATUS_REFUNDED,
                'used_at' => now(),
            ]);

            // Refund associated pending/approved commissions
            PartnerCommission::where('referral_usage_id', $usage->id)
                ->whereIn('status', [
                    PartnerCommission::STATUS_PENDING,
                    PartnerCommission::STATUS_APPROVED,
                ])
                ->update(['status' => PartnerCommission::STATUS_REFUNDED]);
        }
    }
}
