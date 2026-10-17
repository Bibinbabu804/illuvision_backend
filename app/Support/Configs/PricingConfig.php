<?php

namespace App\Support\Configs;

use App\Services\SettingsService;

/**
 * PricingConfig - Typed configuration object for pricing operational limits.
 *
 * All values come from the cached SettingsService.
 * No hardcoded business rules or magic strings.
 */
class PricingConfig
{
    private SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Maximum discount percentage allowed.
     */
    public function maximumDiscountPercentage(): int
    {
        return $this->settings->getInt('pricing', 'maximum_discount_percentage', 50);
    }

    /**
     * Minimum booking amount.
     */
    public function minimumBookingAmount(): float
    {
        return $this->settings->getFloat('pricing', 'minimum_booking_amount', 0);
    }

    /**
     * Number of decimal places for pricing precision.
     */
    public function pricingPrecision(): int
    {
        return $this->settings->getInt('pricing', 'pricing_precision', 2);
    }

    /**
     * Maximum preview duration in hours.
     */
    public function maxPreviewDurationHours(): int
    {
        return $this->settings->getInt('pricing', 'max_preview_duration_hours', 720);
    }

    /**
     * Days considered as weekends for pricing purposes.
     */
    public function weekendDays(): array
    {
        return $this->settings->getArray('pricing', 'weekend_days', ['Sat', 'Sun']);
    }

    /**
     * Check if a given day name is a weekend day according to pricing config.
     */
    public function isWeekendDay(string $dayName): bool
    {
        return in_array($dayName, $this->weekendDays(), true);
    }
}
