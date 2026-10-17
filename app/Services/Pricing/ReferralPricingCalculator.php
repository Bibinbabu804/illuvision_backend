<?php

namespace App\Services\Pricing;

use App\Models\ReferralCode;

class ReferralPricingCalculator
{
    /**
     * Calculate referral discount for a campaign.
     *
     * @param ReferralCode $referralCode
     * @param float $campaignTotal  Total campaign amount before referral discount
     * @return array{discount: float, discount_type: string, discount_value: float}
     */
    public function calculate(ReferralCode $referralCode, float $campaignTotal): array
    {
        $discountType = $referralCode->discount_type ?? 'fixed';
        $discountValue = (float) ($referralCode->discount_value ?? 0);

        if ($discountType === 'percentage') {
            $discount = $campaignTotal * ($discountValue / 100.0);
        } else {
            $discount = $discountValue;
        }

        // Cap at campaign total
        $discount = min($discount, $campaignTotal);

        return [
            'discount' => round(max(0, $discount), 2),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
        ];
    }
}
