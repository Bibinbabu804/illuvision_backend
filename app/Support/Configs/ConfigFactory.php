<?php

namespace App\Support\Configs;

use App\Services\SettingsService;

/**
 * ConfigFactory - Factory for creating typed configuration objects.
 *
 * Ensures config objects are built from the cached settings,
 * and are reused throughout the request lifecycle (singleton-like via service container).
 *
 * Usage:
 *   app(ConfigFactory::class)->booking()  → BookingConfig instance
 *   app(ConfigFactory::class)->media()    → MediaConfig instance
 *   app(ConfigFactory::class)->pricing()  → PricingConfig instance
 *   app(ConfigFactory::class)->referral() → ReferralConfig instance
 */
class ConfigFactory
{
    private SettingsService $settingsService;

    /** @var array<string, object> Cached instances per request */
    private array $instances = [];

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    public function booking(): BookingConfig
    {
        if (!isset($this->instances['booking'])) {
            $this->instances['booking'] = new BookingConfig($this->settingsService);
        }
        return $this->instances['booking'];
    }

    public function media(): MediaConfig
    {
        if (!isset($this->instances['media'])) {
            $this->instances['media'] = new MediaConfig($this->settingsService);
        }
        return $this->instances['media'];
    }

    public function pricing(): PricingConfig
    {
        if (!isset($this->instances['pricing'])) {
            $this->instances['pricing'] = new PricingConfig($this->settingsService);
        }
        return $this->instances['pricing'];
    }

    public function referral(): ReferralConfig
    {
        if (!isset($this->instances['referral'])) {
            $this->instances['referral'] = new ReferralConfig($this->settingsService);
        }
        return $this->instances['referral'];
    }

    public function notification(): NotificationConfig
    {
        if (!isset($this->instances['notification'])) {
            $this->instances['notification'] = new NotificationConfig($this->settingsService);
        }
        return $this->instances['notification'];
    }

    /**
     * Clear all cached instances (useful in long-running processes).
     */
    public function clear(): void
    {
        $this->instances = [];
    }
}
