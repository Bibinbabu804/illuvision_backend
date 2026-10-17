<?php

namespace App\Support\Configs;

use App\Services\SettingsService;

/**
 * ReferralConfig - Typed configuration object for referral operational limits.
 *
 * All values come from the cached SettingsService.
 * No hardcoded business rules or magic strings.
 */
class ReferralConfig
{
    private SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Maximum referral discount as a percentage of the booking amount.
     */
    public function maxReferralDiscountPercentage(): int
    {
        return $this->settings->getInt('referral', 'max_referral_discount_percentage', 100);
    }

    /**
     * Maximum referral discount amount.
     */
    public function maxReferralDiscountAmount(): float
    {
        return (float) $this->settings->get('referral', 'max_referral_discount_amount', 0);
    }

    /**
     * Maximum number of times a single user can use referral codes.
     */
    public function maxUsagePerUser(): int
    {
        return $this->settings->getInt('referral', 'max_referral_usage_per_user', 10);
    }

    /**
     * Number of days before a referral code expires.
     */
    public function referralExpiryDays(): int
    {
        return $this->settings->getInt('referral', 'referral_expiry_days', 365);
    }

    /**
     * Maximum partner commission as a percentage.
     */
    public function maxPartnerCommissionPercentage(): int
    {
        return $this->settings->getInt('referral', 'max_partner_commission_percentage', 50);
    }

    /**
     * Maximum commission amount.
     */
    public function maxCommissionAmount(): float
    {
        return $this->settings->getFloat('referral', 'max_commission_amount', 100000);
    }
}
