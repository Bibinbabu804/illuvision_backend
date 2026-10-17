<?php

namespace App\Services\Pricing;

use App\Models\PricingPlan;
use App\Models\PricingPlanDuration;
use Carbon\Carbon;

class DailyPricingCalculator
{
    /**
     * Calculate runtime hours for a single campaign day.
     *
     * @param string $dailyStartTime  e.g. "18:00"
     * @param string $dailyEndTime    e.g. "19:00"
     * @return float
     */
    public function calculateRuntimeHours(string $dailyStartTime, string $dailyEndTime): float
    {
        $start = Carbon::parse($dailyStartTime);
        $end = Carbon::parse($dailyEndTime);

        // Handle overnight (e.g. 22:00 → 02:00)
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $minutes = $start->diffInMinutes($end);

        // Standard full-day representation (00:00 to 23:59) is 1440 minutes (24 hours)
        if ($minutes >= 1439 || (substr($dailyStartTime, 0, 5) === '00:00' && in_array(substr($dailyEndTime, 0, 5), ['23:59', '24:00', '00:00']))) {
            $minutes = 1440;
        }

        $hours = $minutes / 60.0;

        return max(0, $hours);
    }

    /**
     * Convert duration value to total hours.
     */
    public function durationToHours(float|int $value, string $type): float
    {
        return match (strtolower(trim($type))) {
            'minute', 'minutes', 'min', 'mins' => (float) $value / 60.0,
            'day', 'days'                      => (float) $value * 24.0,
            'week', 'weeks'                    => (float) $value * 24.0 * 7.0,
            'month', 'months'                  => (float) $value * 24.0 * 30.0,
            default                            => (float) $value, // Hour
        };
    }

    /**
     * Calculate base price for a single day's runtime using the pricing plan's duration rules.
     *
     * @param PricingPlan $plan
     * @param float $runtimeHours  Runtime hours for this day
     * @return array{price: float, matched_rule: PricingPlanDuration|null}
     */
    public function calculateBasePrice(PricingPlan $plan, float $runtimeHours): array
    {
        $durationRules = $plan->durationRules()
            ->where('is_active', true)
            ->get();

        $matchedRule = null;
        $price = 0.00;

        if ($durationRules->isNotEmpty()) {
            $sortedRules = $durationRules->sortByDesc(function ($rule) {
                return $this->durationToHours($rule->duration_value, $rule->duration_type);
            });

            foreach ($sortedRules as $rule) {
                $ruleHours = $this->durationToHours($rule->duration_value, $rule->duration_type);
                if ($runtimeHours >= $ruleHours) {
                    $matchedRule = $rule;
                    break;
                }
            }

            if ($matchedRule) {
                $ruleHours = $this->durationToHours($matchedRule->duration_value, $matchedRule->duration_type);
                $cycles = (int) ceil($runtimeHours / max(0.0001, $ruleHours));
                $price = (float) $matchedRule->price * $cycles;
            } else {
                $smallestRule = $sortedRules->last();
                if ($smallestRule) {
                    $price = (float) $smallestRule->price;
                    $matchedRule = $smallestRule;
                } else {
                    $price = (float) $plan->base_price * $runtimeHours;
                }
            }
        } else {
            $price = (float) $plan->base_price * $runtimeHours;
        }

        return [
            'price' => round($price, 2),
            'matched_rule' => $matchedRule,
        ];
    }

    /**
     * Generate array of campaign days between start and end dates.
     *
     * @param string $startDate  Y-m-d
     * @param string $endDate    Y-m-d
     * @return Carbon[]
     */
    public function generateCampaignDays(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        $days = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $days[] = $current->copy();
            $current->addDay();
        }

        return $days;
    }
}
