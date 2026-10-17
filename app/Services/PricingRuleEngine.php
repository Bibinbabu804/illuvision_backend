<?php

namespace App\Services;

use App\Models\PricingPlan;
use App\Models\PricingScreenRule;
use App\Models\PricingDurationRule;
use App\Models\PricingPeakRule;
use App\Models\PricingWeekendRule;
use App\Services\Pricing\DailyPricingCalculator;
use Carbon\Carbon;

class PricingRuleEngine
{
    protected DailyPricingCalculator $dailyPricingCalculator;

    public function __construct(DailyPricingCalculator $dailyPricingCalculator)
    {
        $this->dailyPricingCalculator = $dailyPricingCalculator;
    }

    /**
     * Convert duration value to canonical minutes.
     */
    public function durationToMinutes(float $value, string $unit): float
    {
        return match (strtolower(trim($unit))) {
            'minute', 'minutes', 'min', 'mins' => $value,
            'hour', 'hours', 'hr', 'hrs'       => $value * 60.0,
            'day', 'days'                      => $value * 1440.0,
            'week', 'weeks'                    => $value * 10080.0,
            'month', 'months'                  => $value * 43200.0,
            default => throw new \InvalidArgumentException("Unsupported duration unit: {$unit}"),
        };
    }

    /**
     * Convert canonical minutes to requested duration unit.
     */
    public function minutesToDuration(float $minutes, string $unit): float
    {
        return match (strtolower(trim($unit))) {
            'minute', 'minutes', 'min', 'mins' => $minutes,
            'hour', 'hours', 'hr', 'hrs'       => $minutes / 60.0,
            'day', 'days'                      => $minutes / 1440.0,
            'week', 'weeks'                    => $minutes / 10080.0,
            'month', 'months'                  => $minutes / 43200.0,
            default => throw new \InvalidArgumentException("Unsupported duration unit: {$unit}"),
        };
    }

    /**
     * Convert duration value to hours.
     */
    public function durationToHours(float $value, string $unit): float
    {
        $minutes = $this->durationToMinutes($value, $unit);
        return $minutes / 60.0;
    }

    /**
     * Convert hours to other duration units.
     */
    public function hoursToDuration(float $hours, string $unit): float
    {
        $minutes = $hours * 60.0;
        return $this->minutesToDuration($minutes, $unit);
    }

    /**
     * Convert duration from one unit to another via canonical minutes.
     */
    public function convertDuration(float $value, string $fromUnit, string $toUnit): float
    {
        $minutes = $this->durationToMinutes($value, $fromUnit);
        return $this->minutesToDuration($minutes, $toUnit);
    }

    /**
     * Resolve matching Screen Count Rule.
     */
    public function resolveScreenRule(PricingPlan $plan, int $screens): ?PricingScreenRule
    {
        return PricingScreenRule::where('pricing_plan_id', $plan->id)
            ->where('status', 'active')
            ->where('minimum_screens', '<=', $screens)
            ->where(function ($q) use ($screens) {
                $q->whereNull('maximum_screens')
                  ->orWhere('maximum_screens', '>=', $screens);
            })
            ->orderBy('sort_order')
            ->orderByDesc('minimum_screens')
            ->first();
    }

    /**
     * Resolve matching Duration Rule.
     */
    public function resolveDurationRule(PricingPlan $plan, float $durationVal, string $durationUnit): ?PricingDurationRule
    {
        $rules = PricingDurationRule::where('pricing_plan_id', $plan->id)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        foreach ($rules as $rule) {
            $convertedVal = $this->convertDuration($durationVal, $durationUnit, $rule->duration_unit);
            if ($convertedVal >= $rule->minimum_duration && (is_null($rule->maximum_duration) || $convertedVal <= $rule->maximum_duration)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Calculate Combined Discount percentage from Screen and Duration discounts.
     * Formula: 1 - ((1 - A) * (1 - B))
     */
    public function calculateCombinedDiscount(float $screenDiscountFraction, float $durationDiscountFraction): float
    {
        return 1.0 - ((1.0 - $screenDiscountFraction) * (1.0 - $durationDiscountFraction));
    }

    /**
     * Calculate peak overlap hours for a single rule and daily runtime.
     */
    public function calculatePeakOverlap(PricingPeakRule $rule, string $dailyStartTime, string $dailyEndTime): float
    {
        $peakStart = Carbon::parse($rule->start_time);
        $peakEnd = Carbon::parse($rule->end_time);

        if ($peakEnd->lessThanOrEqualTo($peakStart)) {
            $peakEnd->addDay();
        }

        $runtimeStart = Carbon::parse($dailyStartTime);
        $runtimeEnd = Carbon::parse($dailyEndTime);

        if ($runtimeEnd->lessThanOrEqualTo($runtimeStart)) {
            $runtimeEnd->addDay();
        }

        $overlapStart = $runtimeStart->greaterThan($peakStart) ? $runtimeStart->copy() : $peakStart->copy();
        $overlapEnd = $runtimeEnd->lessThan($peakEnd) ? $runtimeEnd->copy() : $peakEnd->copy();

        $overlapHours = 0;
        if ($overlapStart->lessThan($overlapEnd)) {
            $overlapMinutes = $overlapStart->diffInMinutes($overlapEnd);
            $overlapHours = $overlapMinutes / 60.0;
        }

        return $overlapHours;
    }

    /**
     * Check if a peak rule matches the day name.
     */
    public function dayMatchesPeakRule(PricingPeakRule $rule, string $dayName): bool
    {
        if (strtolower($rule->day_of_week) === 'all' || empty($rule->day_of_week)) {
            return true;
        }

        $days = array_map('trim', explode(',', strtolower($rule->day_of_week)));
        return in_array(strtolower($dayName), $days);
    }

    /**
     * Check if a weekend rule matches the day name.
     */
    public function dayMatchesWeekendRule(PricingWeekendRule $rule, string $dayName): bool
    {
        $days = array_map('trim', explode(',', strtolower($rule->days)));
        return in_array(strtolower($dayName), $days);
    }

    /**
     * Main calculation entrypoint.
     */
    public function calculate(PricingPlan $plan, array $params): array
    {
        // 1. Resolve inputs
        $screens = (int) ($params['screens'] ?? $params['screen_count'] ?? 1);
        if (isset($params['screen_ids']) && is_array($params['screen_ids'])) {
            $screens = count($params['screen_ids']);
        }

        $day = $params['day'] ?? 'Sat';
        $time = $params['time'] ?? '12:00';
        $dailyStartTime = $params['daily_start_time'] ?? $params['startTime'] ?? '00:00';
        $dailyEndTime = $params['daily_end_time'] ?? $params['endTime'] ?? '23:59';

        // Duration calculation
        $durationUnit = $plan->minimum_duration_type ?: 'Hour';
        $durationValue = 1.0;
        $totalDays = 1;
        $dailyHours = $this->dailyPricingCalculator->calculateRuntimeHours($dailyStartTime, $dailyEndTime);

        if (isset($params['start_date']) && isset($params['end_date'])) {
            $start = Carbon::parse($params['start_date'])->startOfDay();
            $end = Carbon::parse($params['end_date'])->startOfDay();
            $totalDays = $start->diffInDays($end) + 1;
            
            if (strtolower($durationUnit) === 'month') {
                $fractional = $totalDays / 30.0;
                if (abs($fractional - round($fractional)) < 0.15) {
                    $durationValue = (float) round($fractional);
                } else {
                    $durationValue = round($fractional, 2);
                }
            } elseif (strtolower($durationUnit) === 'week') {
                $durationValue = round($totalDays / 7.0, 2);
            } elseif (strtolower($durationUnit) === 'day') {
                $durationValue = (float) $totalDays;
            } elseif (in_array(strtolower($durationUnit), ['minute', 'minutes', 'min', 'mins'])) {
                $durationValue = $totalDays * ($dailyHours * 60.0);
            } else { // Hour
                $durationValue = $totalDays * $dailyHours;
            }
        } else {
            $durationValue = (float) ($params['duration_hours'] ?? $params['durationHours'] ?? $params['duration'] ?? 1.0);
            $durationUnit = $params['duration_unit'] ?? $params['durationUnit'] ?? 'Hour';
            
            $durationInBaseUnit = $this->convertDuration($durationValue, $durationUnit, $plan->minimum_duration_type ?: 'Hour');
            $durationValue = $durationInBaseUnit;
            $durationUnit = $plan->minimum_duration_type ?: 'Hour';
            
            // If simulator, check if it's based on days, hours, or minutes
            if (strtolower($durationUnit) === 'day') {
                $totalDays = (int) ceil($durationValue);
                $dailyHours = 24.0;
            } elseif (strtolower($durationUnit) === 'week') {
                $totalDays = (int) ceil($durationValue * 7);
                $dailyHours = 24.0;
            } elseif (strtolower($durationUnit) === 'month') {
                $totalDays = (int) ceil($durationValue * 30);
                $dailyHours = 24.0;
            } elseif (in_array(strtolower($durationUnit), ['minute', 'minutes', 'min', 'mins'])) {
                $totalDays = 1;
                $dailyHours = $durationValue / 60.0;
            } else {
                $totalDays = 1;
                $dailyHours = $durationValue;
            }
        }

        // 2. Base Price
        $basePricePerScreen = (float) $plan->base_price * $durationValue;
        $totalBasePrice = $basePricePerScreen * $screens;

        // 3. Screen Count Discount
        $screenRule = $this->resolveScreenRule($plan, $screens);
        $screenDiscountFraction = 0.0;
        if ($screenRule) {
            if (strtolower($screenRule->discount_type) === 'percentage') {
                $screenDiscountFraction = (float) $screenRule->discount_value / 100.0;
            } else {
                $screenDiscountFraction = $totalBasePrice > 0 ? (float) $screenRule->discount_value / $totalBasePrice : 0.0;
            }
        }

        // 4. Duration Discount
        $durationRule = $this->resolveDurationRule($plan, $durationValue, $durationUnit);
        $durationDiscountFraction = 0.0;
        if ($durationRule) {
            if (strtolower($durationRule->discount_type) === 'percentage') {
                $durationDiscountFraction = (float) $durationRule->discount_value / 100.0;
            } else {
                $durationDiscountFraction = $totalBasePrice > 0 ? (float) $durationRule->discount_value / $totalBasePrice : 0.0;
            }
        }

        // 5. Combined Discount Calculation
        $combinedDiscountFraction = $this->calculateCombinedDiscount($screenDiscountFraction, $durationDiscountFraction);
        $screenDiscountAmount = $totalBasePrice * $screenDiscountFraction;
        $durationDiscountAmount = ($totalBasePrice - $screenDiscountAmount) * $durationDiscountFraction;
        $discountAmount = $totalBasePrice * $combinedDiscountFraction;
        $subtotal = $totalBasePrice - $discountAmount;

        // 6. Peak Hour Rules & Weekend Rules
        $peakPricing = 0.00;
        $weekendPricing = 0.00;
        $peakRules = $plan->peakRules()->where('status', 'active')->get();
        $weekendRules = $plan->weekendRules()->where('status', 'active')->get();

        $dailyBreakdown = [];
        $matchedPeakRule = null;
        $matchedWeekendRule = null;

        if (isset($params['start_date']) && isset($params['end_date'])) {
            $start = Carbon::parse($params['start_date'])->startOfDay();
            $end = Carbon::parse($params['end_date'])->startOfDay();
            $current = $start->copy();
            
            $dailyBasePerScreen = $totalDays > 0 ? $basePricePerScreen / $totalDays : 0;
            $dailySubtotalPerScreen = $totalDays > 0 ? ($subtotal / $screens) / $totalDays : 0;

            while ($current->lte($end)) {
                $currentDayName = $current->format('D');
                $dayPeakCharge = 0.0;
                $dayWeekendCharge = 0.0;
                $appliedPeakRule = null;
                $appliedWeekendRule = null;

                // Peak Hour check for this day
                foreach ($peakRules as $rule) {
                    if ($this->dayMatchesPeakRule($rule, $currentDayName)) {
                        $overlapHours = $this->calculatePeakOverlap($rule, $dailyStartTime, $dailyEndTime);
                        if ($overlapHours > 0) {
                            $hourlyRate = $dailyHours > 0 ? $dailySubtotalPerScreen / $dailyHours : 0;
                            
                            $charge = 0;
                            if ($rule->pricing_type === 'multiplier') {
                                $charge = $hourlyRate * $overlapHours * ((float) $rule->value - 1.0);
                            } elseif ($rule->pricing_type === 'percentage') {
                                $charge = $hourlyRate * $overlapHours * ((float) $rule->value / 100.0);
                            } else {
                                $charge = (float) $rule->value * $overlapHours;
                            }
                            
                            $dayPeakCharge += $charge;
                            $appliedPeakRule = $rule;
                            $matchedPeakRule = $rule;
                        }
                    }
                }

                // Weekend check for this day
                foreach ($weekendRules as $rule) {
                    if ($this->dayMatchesWeekendRule($rule, $currentDayName)) {
                        $charge = 0;
                        if ($rule->pricing_type === 'multiplier') {
                            $charge = $dailySubtotalPerScreen * ((float) $rule->value - 1.0);
                        } elseif ($rule->pricing_type === 'percentage') {
                            $charge = $dailySubtotalPerScreen * ((float) $rule->value / 100.0);
                        } else {
                            $charge = (float) $rule->value;
                        }
                        
                        $dayWeekendCharge += $charge;
                        $appliedWeekendRule = $rule;
                        $matchedWeekendRule = $rule;
                    }
                }

                $daySubtotal = $dailySubtotalPerScreen + $dayPeakCharge + $dayWeekendCharge;
                $peakPricing += $dayPeakCharge * $screens;
                $weekendPricing += $dayWeekendCharge * $screens;

                $dailyBreakdown[] = [
                    'date' => $current->format('Y-m-d'),
                    'day_name' => $currentDayName,
                    'runtime_hours' => round($dailyHours, 2),
                    'base_price' => round($dailyBasePerScreen, 2),
                    'peak_charge' => round($dayPeakCharge, 2),
                    'weekend_charge' => round($dayWeekendCharge, 2),
                    'day_subtotal' => round($daySubtotal, 2),
                    'applied_duration_rule' => $durationRule ? [
                        'id' => $durationRule->id,
                        'minimum_duration' => (float) $durationRule->minimum_duration,
                        'discount_value' => (float) $durationRule->discount_value,
                        'duration_unit' => $durationRule->duration_unit,
                    ] : null,
                    'applied_peak_rule' => $appliedPeakRule ? [
                        'id' => $appliedPeakRule->id,
                        'start_time' => $appliedPeakRule->start_time,
                        'end_time' => $appliedPeakRule->end_time,
                        'pricing_type' => $appliedPeakRule->pricing_type,
                        'value' => (float) $appliedPeakRule->value,
                    ] : null,
                    'applied_weekend_rule' => $appliedWeekendRule ? [
                        'id' => $appliedWeekendRule->id,
                        'pricing_type' => $appliedWeekendRule->pricing_type,
                        'value' => (float) $appliedWeekendRule->value,
                    ] : null,
                ];

                $current->addDay();
            }
        } else {
            // Simulator preview mode
            $dailySubtotalPerScreen = $screens > 0 ? $subtotal / $screens : 0;
            $dayPeakCharge = 0.0;
            $dayWeekendCharge = 0.0;

            // Peak Hour check
            foreach ($peakRules as $rule) {
                if ($this->dayMatchesPeakRule($rule, $day)) {
                    $overlapHours = $this->calculatePeakOverlap($rule, $time, Carbon::parse($time)->addHours($dailyHours)->format('H:i'));
                    if ($overlapHours > 0) {
                        $hourlyRate = $dailyHours > 0 ? $dailySubtotalPerScreen / $dailyHours : 0;
                        
                        $charge = 0;
                        if ($rule->pricing_type === 'multiplier') {
                            $charge = $hourlyRate * $overlapHours * ((float) $rule->value - 1.0);
                        } elseif ($rule->pricing_type === 'percentage') {
                            $charge = $hourlyRate * $overlapHours * ((float) $rule->value / 100.0);
                        } else {
                            $charge = (float) $rule->value * $overlapHours;
                        }
                        
                        $dayPeakCharge += $charge;
                        $matchedPeakRule = $rule;
                    }
                }
            }

            // Weekend check
            foreach ($weekendRules as $rule) {
                if ($this->dayMatchesWeekendRule($rule, $day)) {
                    $charge = 0;
                    if ($rule->pricing_type === 'multiplier') {
                        $charge = $dailySubtotalPerScreen * ((float) $rule->value - 1.0);
                    } elseif ($rule->pricing_type === 'percentage') {
                        $charge = $dailySubtotalPerScreen * ((float) $rule->value / 100.0);
                    } else {
                        $charge = (float) $rule->value;
                    }
                    
                    $dayWeekendCharge += $charge;
                    $matchedWeekendRule = $rule;
                }
            }

            $peakPricing = $dayPeakCharge * $screens;
            $weekendPricing = $dayWeekendCharge * $screens;

            $dailyBreakdown[] = [
                'date' => Carbon::now()->format('Y-m-d'),
                'day_name' => $day,
                'runtime_hours' => round($dailyHours, 2),
                'base_price' => round($basePricePerScreen, 2),
                'peak_charge' => round($dayPeakCharge, 2),
                'weekend_charge' => round($dayWeekendCharge, 2),
                'day_subtotal' => round($dailySubtotalPerScreen + $dayPeakCharge + $dayWeekendCharge, 2),
                'applied_duration_rule' => $durationRule ? [
                    'id' => $durationRule->id,
                    'minimum_duration' => (float) $durationRule->minimum_duration,
                    'discount_value' => (float) $durationRule->discount_value,
                    'duration_unit' => $durationRule->duration_unit,
                ] : null,
                'applied_peak_rule' => $matchedPeakRule ? [
                    'id' => $matchedPeakRule->id,
                    'start_time' => $matchedPeakRule->start_time,
                    'end_time' => $matchedPeakRule->end_time,
                    'pricing_type' => $matchedPeakRule->pricing_type,
                    'value' => (float) $matchedPeakRule->value,
                ] : null,
                'applied_weekend_rule' => $matchedWeekendRule ? [
                    'id' => $matchedWeekendRule->id,
                    'pricing_type' => $matchedWeekendRule->pricing_type,
                    'value' => (float) $matchedWeekendRule->value,
                ] : null,
            ];
        }

        // Final Amount calculation before booking discounts
        $finalPrice = $subtotal + $peakPricing + $weekendPricing;

        return [
            'base_price' => round($totalBasePrice, 2),
            'discount_amount' => round($discountAmount, 2),
            'screen_discount_amount' => round($screenDiscountAmount, 2),
            'duration_discount_amount' => round($durationDiscountAmount, 2),
            'screen_discount_fraction' => $screenDiscountFraction,
            'duration_discount_fraction' => $durationDiscountFraction,
            'peak_amount' => round($peakPricing, 2),
            'weekend_amount' => round($weekendPricing, 2),
            'final_price' => round(max(0.00, $finalPrice), 2),
            'combined_discount' => round($combinedDiscountFraction * 100, 2),
            'daily_breakdown' => $dailyBreakdown,
            'applied_rules' => [
                'screen_rule' => $screenRule ? [
                    'id' => $screenRule->id,
                    'minimum_screens' => $screenRule->minimum_screens,
                    'maximum_screens' => $screenRule->maximum_screens,
                    'discount_type' => $screenRule->discount_type,
                    'discount_value' => (float) $screenRule->discount_value,
                ] : null,
                'duration_rule' => $durationRule ? [
                    'id' => $durationRule->id,
                    'minimum_duration' => (float) $durationRule->minimum_duration,
                    'maximum_duration' => (float) $durationRule->maximum_duration,
                    'duration_unit' => $durationRule->duration_unit,
                    'discount_type' => $durationRule->discount_type,
                    'discount_value' => (float) $durationRule->discount_value,
                ] : null,
                'peak_rule' => $matchedPeakRule ? [
                    'id' => $matchedPeakRule->id,
                    'day_of_week' => $matchedPeakRule->day_of_week,
                    'start_time' => $matchedPeakRule->start_time,
                    'end_time' => $matchedPeakRule->end_time,
                    'pricing_type' => $matchedPeakRule->pricing_type,
                    'value' => (float) $matchedPeakRule->value,
                ] : null,
                'weekend_rule' => $matchedWeekendRule ? [
                    'id' => $matchedWeekendRule->id,
                    'days' => $matchedWeekendRule->days,
                    'pricing_type' => $matchedWeekendRule->pricing_type,
                    'value' => (float) $matchedWeekendRule->value,
                ] : null,
            ]
        ];
    }
}
