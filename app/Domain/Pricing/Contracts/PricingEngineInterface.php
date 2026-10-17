<?php

namespace App\Domain\Pricing\Contracts;

/**
 * PricingEngineInterface
 *
 * Defines the canonical contract for marketplace campaign pricing calculations.
 */
interface PricingEngineInterface
{
    /**
     * Calculate campaign pricing preview.
     *
     * @param array $params [
     *   'screen_ids' => int[],
     *   'quantities' => array<int, int>,
     *   'start_date' => string (Y-m-d),
     *   'end_date' => string (Y-m-d),
     *   'daily_start_time' => string (H:i),
     *   'daily_end_time' => string (H:i),
     *   'referral_code_id' => int|null,
     *   'redeem_points' => int,
     *   'user' => \App\Models\User|null,
     *   'country_code' => string|null,
     * ]
     * @return array Standardized canonical pricing result payload
     */
    public function calculate(array $params): array;

    /**
     * Validate minimum duration requirement for screens.
     *
     * @param array $screenIds
     * @param string $startDate
     * @param string $endDate
     * @param string $dailyStartTime
     * @param string $dailyEndTime
     * @return array List of validation error strings (empty if valid)
     */
    public function validateMinimumDuration(
        array $screenIds,
        string $startDate,
        string $endDate,
        string $dailyStartTime = '00:00',
        string $dailyEndTime = '23:59'
    ): array;

    /**
     * Convert minimum duration value to days.
     */
    public function convertMinDurationToDays(int|float $value, string $type): float;
}
