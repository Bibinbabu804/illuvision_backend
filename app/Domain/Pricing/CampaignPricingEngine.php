<?php

namespace App\Domain\Pricing;

use App\Domain\Pricing\Contracts\PricingEngineInterface;
use App\Models\ReferralCode;
use App\Models\RewardAccount;
use App\Models\Screen;
use App\Services\Campaign\LoyaltyService;
use App\Services\PricingRuleEngine;
use App\Services\Pricing\DailyPricingCalculator;
use App\Services\Pricing\FirstBookingDiscountPricingCalculator;
use App\Services\Pricing\ReferralPricingCalculator;
use App\Services\Pricing\ScreenPricingResolver;
use App\Services\SettingsService;
use Carbon\Carbon;

/**
 * CampaignPricingEngine
 *
 * The canonical domain calculation engine for campaign pricing in the Illuvision Marketplace.
 * Owns base rate calculation, screen multipliers, duration rules, peak & weekend surcharges,
 * referral discounts, first booking discounts, and loyalty point conversions.
 */
class CampaignPricingEngine implements PricingEngineInterface
{
    public function __construct(
        protected ScreenPricingResolver $screenPricingResolver,
        protected DailyPricingCalculator $dailyPricingCalculator,
        protected ReferralPricingCalculator $referralPricingCalculator,
        protected FirstBookingDiscountPricingCalculator $firstBookingDiscountPricingCalculator,
        protected PricingRuleEngine $pricingRuleEngine,
        protected LoyaltyService $loyaltyService,
        protected SettingsService $settingsService,
    ) {
    }

    /**
     * Calculate campaign pricing preview.
     *
     * @param array $params
     * @return array
     */
    public function calculate(array $params): array
    {
        $screenIds = $params['screen_ids'];
        $quantities = $params['quantities'] ?? [];
        $startDate = $params['start_date'];
        $endDate = $params['end_date'];
        $dailyStartTime = $params['daily_start_time'] ?? '00:00';
        $dailyEndTime = $params['daily_end_time'] ?? '23:59';
        $referralCodeId = $params['referral_code_id'] ?? null;
        $user = $params['user'] ?? null;

        // 1. Generate campaign days (needed for summary details)
        $campaignDays = $this->dailyPricingCalculator->generateCampaignDays($startDate, $endDate);
        $totalCampaignDays = count($campaignDays);

        // 2. Resolve screen pricing plans
        $screenPlans = $this->screenPricingResolver->resolveMany($screenIds);

        // 3. Calculate per-screen pricing using PricingRuleEngine
        $perScreenBreakdowns = [];
        $totalBasePrice = 0;
        $totalPeakCharge = 0;
        $totalWeekendCharge = 0;
        $totalDiscountAmount = 0;
        $totalScreenDiscount = 0;
        $totalDurationDiscount = 0;
        $totalAfterDiscount = 0;

        foreach ($screenPlans as $screenId => $plan) {
            $calcParams = [
                'screen_ids' => $screenIds,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_start_time' => $dailyStartTime,
                'daily_end_time' => $dailyEndTime,
            ];

            $screenResult = $this->pricingRuleEngine->calculate($plan, $calcParams);

            // Get quantity for this screen
            $screenQuantity = max(1, (int) ($quantities[$screenId] ?? 1));

            // Divide by total screens to get this screen's portion of booking-level prices
            $totalScreensCount = max(1, count($screenIds));
            $screenBasePrice = ($screenResult['base_price'] / $totalScreensCount) * $screenQuantity;
            $screenDiscountAmount = ($screenResult['discount_amount'] / $totalScreensCount) * $screenQuantity;
            $screenScreenDiscount = (($screenResult['screen_discount_amount'] ?? 0) / $totalScreensCount) * $screenQuantity;
            $screenDurationDiscount = (($screenResult['duration_discount_amount'] ?? 0) / $totalScreensCount) * $screenQuantity;
            $screenPeakCharge = ($screenResult['peak_amount'] / $totalScreensCount) * $screenQuantity;
            $screenWeekendCharge = ($screenResult['weekend_amount'] / $totalScreensCount) * $screenQuantity;
            $screenSubtotalAfterDiscount = ($screenResult['final_price'] / $totalScreensCount) * $screenQuantity;

            $perScreenBreakdowns[] = [
                'screen_id' => $screenId,
                'quantity' => $screenQuantity,
                'pricing_plan_id' => $plan->id,
                'pricing_plan_name' => $plan->name,
                'daily_breakdown' => $screenResult['daily_breakdown'] ?? [],
                'screen_total' => [
                    'base_price' => round($screenBasePrice, 2),
                    'quantity' => $screenQuantity,
                    'peak_charge' => round($screenPeakCharge, 2),
                    'weekend_charge' => round($screenWeekendCharge, 2),
                    'discount_amount' => round($screenDiscountAmount, 2),
                    'screen_discount' => round($screenScreenDiscount, 2),
                    'duration_discount' => round($screenDurationDiscount, 2),
                    'subtotal_before_discount' => round($screenBasePrice + $screenPeakCharge + $screenWeekendCharge, 2),
                    'subtotal_after_discount' => round($screenSubtotalAfterDiscount, 2),
                ],
                'applied_rules' => $screenResult['applied_rules'] ?? [],
                'applied_discount_rule' => $screenResult['applied_rules']['duration_rule'] ?? null,
                'applied_screen_rule' => $screenResult['applied_rules']['screen_rule'] ?? null,
            ];

            $totalBasePrice += $screenBasePrice;
            $totalPeakCharge += $screenPeakCharge;
            $totalWeekendCharge += $screenWeekendCharge;
            $totalDiscountAmount += $screenDiscountAmount;
            $totalScreenDiscount += $screenScreenDiscount;
            $totalDurationDiscount += $screenDurationDiscount;
            $totalAfterDiscount += $screenSubtotalAfterDiscount;
        }

        // 4. Apply first booking discount
        $firstBookingDiscountEligible = false;
        $firstBookingDiscount = 0;
        $firstBookingDiscountSnapshot = null;

        if ($user) {
            $fbdResult = $this->firstBookingDiscountPricingCalculator->calculate($user, $totalAfterDiscount);
            $firstBookingDiscountEligible = $fbdResult['eligible'] ?? false;
            $firstBookingDiscount = $fbdResult['amount'] ?? 0;
            $firstBookingDiscountSnapshot = $fbdResult['settings_snapshot'] ?? null;
        }

        // 5. Apply referral discount
        $referralDiscount = 0;
        $referralInfo = null;

        if ($referralCodeId) {
            $referralCode = ReferralCode::find($referralCodeId);
            if ($referralCode) {
                $referralResult = $this->referralPricingCalculator->calculate($referralCode, $totalAfterDiscount - $firstBookingDiscount);
                $referralDiscount = $referralResult['discount'];
                $referralInfo = $referralResult;
            }
        }

        $netAfterDiscounts = max(0.00, $totalAfterDiscount - $firstBookingDiscount - $referralDiscount);

        // 6. Apply Loyalty Reward points discount
        $redeemPoints = (int) ($params['redeem_points'] ?? 0);
        $pointsToRedeem = 0;
        $rewardDiscount = 0.00;
        $disabledReason = null;
        $availablePoints = 0;
        $loyaltyEnabled = $this->settingsService->getBool('loyalty', 'enable_rewards', true);
        $redemptionEnabled = $this->settingsService->getBool('loyalty', 'enable_redemption', true);

        if ($user) {
            $account = RewardAccount::where('user_id', $user->id)->first();
            $availablePoints = $account ? $account->balance : 0;
        }

        if ($redeemPoints > 0) {
            $hasCoupon = !empty($params['coupon_code']);

            if (!$loyaltyEnabled || !$redemptionEnabled) {
                $disabledReason = 'Reward system is currently disabled.';
            } elseif ($referralCodeId && !$this->settingsService->getBool('loyalty', 'allow_reward_and_referral', false)) {
                $disabledReason = 'Reward points cannot be combined with a referral discount.';
            } elseif ($hasCoupon && !$this->settingsService->getBool('loyalty', 'allow_reward_and_coupon', true)) {
                $disabledReason = 'Reward points cannot be combined with a coupon.';
            } else {
                $minPointsToRedeem = $this->settingsService->getInt('loyalty', 'min_points_to_redeem', 100);
                if ($availablePoints < $minPointsToRedeem) {
                    $disabledReason = "You need at least {$minPointsToRedeem} points to redeem.";
                } elseif ($redeemPoints < $minPointsToRedeem) {
                    $disabledReason = "Minimum points to redeem is {$minPointsToRedeem}.";
                } else {
                    $pointValue = (float) $this->settingsService->get('loyalty', 'redemption_point_value', 1.00);
                    $maxPointsAllowed = $this->settingsService->getInt('loyalty', 'max_points_redeem_per_booking', 1000);
                    $maxDiscountPercentage = $this->settingsService->getInt('loyalty', 'max_discount_percentage', 50);
                    $maxDiscountAmount = (float) $this->settingsService->get('loyalty', 'max_discount_amount', 0);
                    $minBookingAmount = (float) $this->settingsService->get('loyalty', 'min_booking_amount', 0);

                    if ($minBookingAmount > 0 && $netAfterDiscounts < $minBookingAmount) {
                        $disabledReason = "Minimum booking amount of ₹{$minBookingAmount} required to redeem points.";
                    } else {
                        $allowedPoints = min($redeemPoints, $availablePoints, $maxPointsAllowed);

                        $maxDiscountFromPercentage = $netAfterDiscounts * ($maxDiscountPercentage / 100);
                        $maxPointsFromPercentage = (int) floor($maxDiscountFromPercentage / $pointValue);
                        $allowedPoints = min($allowedPoints, $maxPointsFromPercentage);

                        if ($maxDiscountAmount > 0) {
                            $maxPointsFromDiscountAmount = (int) floor($maxDiscountAmount / $pointValue);
                            $allowedPoints = min($allowedPoints, $maxPointsFromDiscountAmount);
                        }

                        $maxPointsFromNet = (int) floor($netAfterDiscounts / $pointValue);
                        $allowedPoints = min($allowedPoints, $maxPointsFromNet);

                        if ($allowedPoints > 0) {
                            $pointsToRedeem = $allowedPoints;
                            $rewardDiscount = round($pointsToRedeem * $pointValue, 2);
                        } else {
                            $disabledReason = "Invalid redemption request.";
                        }
                    }
                }
            }
        }

        $finalTotal = round(max(0, $netAfterDiscounts - $rewardDiscount), 2);
        $pointsToEarn = $this->loyaltyService->calculatePointsForAmount($finalTotal);

        return [
            'campaign_summary' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_days' => $totalCampaignDays,
                'daily_runtime' => [
                    'start_time' => $dailyStartTime,
                    'end_time' => $dailyEndTime,
                ],
                'total_screens' => count($screenIds),
            ],
            'per_screen' => $perScreenBreakdowns,
            'aggregated' => [
                'base_price' => round($totalBasePrice, 2),
                'screen_count' => count($screenIds),
                'screen_discount' => round($totalScreenDiscount, 2),
                'duration_discount' => round($totalDurationDiscount, 2),
                'combined_discount' => round($totalDiscountAmount, 2),
                'peak_adjustment' => round($totalPeakCharge, 2),
                'weekend_adjustment' => round($totalWeekendCharge, 2),
                'first_booking_discount' => round($firstBookingDiscount, 2),
                'referral_discount' => round($referralDiscount, 2),
                'reward_points_used' => $pointsToRedeem,
                'reward_discount' => round($rewardDiscount, 2),
                'reward_points_earned' => $pointsToEarn,
                'rewards_disabled_reason' => $disabledReason,
                'subtotal' => round($totalAfterDiscount + $totalPeakCharge + $totalWeekendCharge, 2),
                'final_amount' => $finalTotal,
                // Backward compatibility keys
                'total_base_price' => round($totalBasePrice, 2),
                'total_peak_charge' => round($totalPeakCharge, 2),
                'total_weekend_charge' => round($totalWeekendCharge, 2),
                'total_discount' => round($totalDiscountAmount, 2),
                'first_booking_discount_eligible' => $firstBookingDiscountEligible,
                'subtotal_before_discount' => round($totalBasePrice + $totalPeakCharge + $totalWeekendCharge, 2),
                'final_total' => $finalTotal,
            ],
            'applied_referral' => $referralInfo,
            'first_booking_discount' => [
                'eligible' => $firstBookingDiscountEligible,
                'amount' => round($firstBookingDiscount, 2),
                'settings_snapshot' => $firstBookingDiscountSnapshot,
            ],
            'loyalty' => [
                'enabled' => $loyaltyEnabled && $redemptionEnabled,
                'balance' => $availablePoints,
                'redeemed_points' => $pointsToRedeem,
                'discount_amount' => round($rewardDiscount, 2),
                'points_earned' => $pointsToEarn,
                'remaining_balance' => max(0, $availablePoints - $pointsToRedeem),
                'disabled_reason' => $disabledReason,
            ],
        ];
    }

    /**
     * Validate that the campaign duration meets the minimum requirement
     * for all selected screens' pricing plans.
     */
    public function validateMinimumDuration(
        array $screenIds,
        string $startDate,
        string $endDate,
        string $dailyStartTime = '00:00',
        string $dailyEndTime = '23:59'
    ): array {
        $errors = [];

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        $totalDays = $start->diffInDays($end) + 1;

        $dailyRuntimeHours = $this->dailyPricingCalculator->calculateRuntimeHours($dailyStartTime, $dailyEndTime);
        $totalCampaignMinutes = $totalDays * ($dailyRuntimeHours * 60.0);

        $screens = Screen::with('pricingPlan')->whereIn('id', $screenIds)->get();

        foreach ($screens as $screen) {
            if (!$screen->pricingPlan) continue;
            $plan = $screen->pricingPlan;
            $planMinMinutes = $this->pricingRuleEngine->durationToMinutes(
                (float) ($plan->minimum_duration ?? 1),
                $plan->minimum_duration_type ?: 'Hour'
            );
            if (($totalCampaignMinutes + 0.001) < $planMinMinutes) {
                $errors[] = "Screen '{$screen->name}' requires a minimum campaign of {$plan->minimum_duration} {$plan->minimum_duration_type}(s).";
            }
        }

        return $errors;
    }

    /**
     * Convert minimum duration value to days.
     */
    public function convertMinDurationToDays(int|float $value, string $type): float
    {
        return match (strtolower(trim($type))) {
            'minute', 'minutes', 'min', 'mins' => (float) $value / 1440.0,
            'hour', 'hours', 'hr', 'hrs'       => (float) $value / 24.0,
            'day', 'days'                      => (float) $value,
            'week', 'weeks'                    => (float) $value * 7.0,
            'month', 'months'                  => (float) $value * 30.0,
            default                            => (float) $value,
        };
    }
}
