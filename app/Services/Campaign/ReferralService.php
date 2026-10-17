<?php

namespace App\Services\Campaign;

use App\Models\ReferralCode;
use App\Models\ReferralUsage;
use App\Models\PartnerCommission;

class ReferralService
{
    /**
     * Validate a referral code and return discount/commission details.
     * Does NOT create any records — pure validation.
     */
    public function validate(string $code, float $subtotal): ?array
    {
        $referralCode = ReferralCode::where('code', $code)->valid()->first();

        if (!$referralCode) {
            return null;
        }

        $discountAmount = $this->calculateDiscount($referralCode, $subtotal);
        $commissionAmount = $this->calculateCommission($referralCode, $subtotal);

        return [
            'referral_code' => $referralCode,
            'discount_amount' => $discountAmount,
            'commission_amount' => $commissionAmount,
        ];
    }

    /**
     * Calculate customer discount based on referral code configuration.
     */
    public function calculateDiscount(ReferralCode $code, float $subtotal): float
    {
        if ($code->discount_type === 'percentage') {
            $discount = $subtotal * ($code->discount_value / 100);
            if ($code->max_discount_amount && $discount > $code->max_discount_amount) {
                $discount = $code->max_discount_amount;
            }
            return round($discount, 2);
        }

        // Fixed amount
        $discount = $code->discount_value;
        if ($code->max_discount_amount && $discount > $code->max_discount_amount) {
            $discount = $code->max_discount_amount;
        }
        return round(min($discount, $subtotal), 2);
    }

    /**
     * Calculate partner commission based on referral code configuration.
     * Sprint B1: Profit-Based Partner Commission Engine.
     * Commission percentage is calculated on Illuvision Revenue (partner_commission_base).
     */
    public function calculateCommission(ReferralCode $code, float $commissionBase): float
    {
        if ($code->commission_type === 'percentage') {
            $commission = $commissionBase * ($code->commission_value / 100);
            if ($code->max_commission_amount && $commission > $code->max_commission_amount) {
                $commission = $code->max_commission_amount;
            }
            return round($commission, 2);
        }

        // Fixed amount
        $commission = $code->commission_value;
        if ($code->max_commission_amount && $commission > $code->max_commission_amount) {
            $commission = $code->max_commission_amount;
        }
        return round($commission, 2);
    }

    /**
     * Create a referral usage record after successful payment.
     * Sprint B1: Stores campaign_total, provider_revenue, illuvision_revenue, partner_commission_base, partner_commission_percentage.
     */
    public function createUsage(
        ReferralCode $referralCode,
        int $bookingId,
        int $userId,
        int $paymentId,
        ?int $partnerId = null,
        float $discountAmount = 0.0,
        float $commissionAmount = 0.0,
        float $finalBookingAmount = 0.0,
        array $commissionSnapshots = []
    ): ReferralUsage {
        $usage = ReferralUsage::create([
            'referral_code_id' => $referralCode->id,
            'booking_id' => $bookingId,
            'user_id' => $userId,
            'payment_id' => $paymentId,
            'partner_id' => $partnerId,
            'discount_amount' => $discountAmount,
            'commission_amount' => $commissionAmount,
            'final_booking_amount' => $finalBookingAmount,
            'campaign_total' => $commissionSnapshots['campaign_total'] ?? $finalBookingAmount,
            'provider_revenue' => $commissionSnapshots['provider_revenue'] ?? 0.0,
            'illuvision_revenue' => $commissionSnapshots['illuvision_revenue'] ?? $finalBookingAmount,
            'partner_commission_base' => $commissionSnapshots['partner_commission_base'] ?? ($commissionSnapshots['illuvision_revenue'] ?? $finalBookingAmount),
            'partner_commission_percentage' => $commissionSnapshots['partner_commission_percentage'] ?? ($referralCode->commission_type === 'percentage' ? (float) $referralCode->commission_value : 0.0),
            'status' => ReferralUsage::STATUS_CONFIRMED,
            'used_at' => now(),
        ]);

        // Increment usage count on the referral code
        $referralCode->increment('used_count');

        return $usage;
    }

    /**
     * Get referral analytics summary.
     */
    public function getAnalytics(): array
    {
        $totalReferralRevenue = ReferralUsage::whereIn('status', ['confirmed', 'paid'])
            ->sum('discount_amount');

        $totalCommissionEarned = PartnerCommission::sum('amount');
        $pendingCommission = PartnerCommission::where('status', 'pending')->sum('amount');
        $paidCommission = PartnerCommission::where('status', 'paid')->sum('amount');

        $totalUsages = ReferralUsage::count();
        $confirmedUsages = ReferralUsage::whereIn('status', ['confirmed', 'paid'])->count();

        $topPartners = \App\Models\Partner::withCount(['referralCodes', 'commissions as total_commission' => function ($q) {
                $q->select(\Illuminate\Support\Facades\DB::raw('COALESCE(SUM(amount), 0)'));
            }])
            ->orderByDesc('total_commission')
            ->limit(10)
            ->get();

        $topCodes = ReferralCode::withCount('usages')
            ->withSum('usages', 'commission_amount')
            ->orderByDesc('usages_count')
            ->limit(10)
            ->get();

        return [
            'total_referral_revenue' => $totalReferralRevenue,
            'total_commission_earned' => $totalCommissionEarned,
            'pending_commission' => $pendingCommission,
            'paid_commission' => $paidCommission,
            'total_usages' => $totalUsages,
            'confirmed_usages' => $confirmedUsages,
            'conversion_rate' => $totalUsages > 0 ? round(($confirmedUsages / $totalUsages) * 100, 1) : 0,
            'top_partners' => $topPartners,
            'top_codes' => $topCodes,
        ];
    }

    /**
     * Get commission summary for a specific partner.
     */
    public function getPartnerCommissionSummary(int $partnerId): array
    {
        $totalEarned = PartnerCommission::where('partner_id', $partnerId)->sum('amount');
        $pending = PartnerCommission::where('partner_id', $partnerId)->where('status', 'pending')->sum('amount');
        $paid = PartnerCommission::where('partner_id', $partnerId)->where('status', 'paid')->sum('amount');
        $cancelled = PartnerCommission::where('partner_id', $partnerId)->where('status', 'cancelled')->sum('amount');

        return [
            'total_earned' => $totalEarned,
            'pending' => $pending,
            'paid' => $paid,
            'cancelled' => $cancelled,
        ];
    }
}
