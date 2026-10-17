<?php

namespace App\Services\Pricing;

use App\Services\FirstBookingDiscountService;
use App\Models\User;

class FirstBookingDiscountPricingCalculator
{
    protected FirstBookingDiscountService $firstBookingDiscountService;

    public function __construct(FirstBookingDiscountService $firstBookingDiscountService)
    {
        $this->firstBookingDiscountService = $firstBookingDiscountService;
    }

    /**
     * Calculate first booking discount for a campaign.
     *
     * @param User|null $user  The authenticated user (or null for guests)
     * @param float $campaignTotal  Total campaign amount before first booking discount
     * @return array{eligible: bool, amount: float, percentage: float, max_amount: float, settings_snapshot: array}
     */
    public function calculate(?User $user, float $campaignTotal): array
    {
        
        return $this->firstBookingDiscountService->validateAndCalculate($user, $campaignTotal);
    }
}
