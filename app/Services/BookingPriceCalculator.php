<?php

namespace App\Services;

use App\Models\PricingPlan;

class BookingPriceCalculator
{
    protected PricingRuleEngine $ruleEngine;

    public function __construct(PricingRuleEngine $ruleEngine)
    {
        $this->ruleEngine = $ruleEngine;
    }

    /**
     * Convert a duration value and type to hours.
     */
    public function durationToHours(int $value, string $type): int
    {
        return (int) $this->ruleEngine->durationToHours($value, $type);
    }

    /**
     * Check if a time falls in a start/end range.
     */
    public function timeInRange(string $time, string $start, string $end): bool
    {
        $timeStr = substr($time, 0, 5);
        $startStr = substr($start, 0, 5);
        $endStr = substr($end, 0, 5);

        return $timeStr >= $startStr && $timeStr < $endStr;
    }

    /**
     * Calculate price and return detailed breakdown.
     */
    public function calculate(PricingPlan $plan, array $preview): array
    {
        return $this->ruleEngine->calculate($plan, $preview);
    }
}
