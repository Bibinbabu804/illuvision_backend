<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * SettingsService - Centralized Configuration with Caching
 *
 * Loads settings from the database ONCE per request cycle (or cache TTL),
 * then provides typed accessors for all application settings.
 *
 * Cache key: system_settings
 * Cache TTL: 12 hours
 * Cache is invalidated whenever admin updates any setting group.
 */
class SettingsService
{
    const CACHE_KEY = 'system_settings';
    const CACHE_TTL_HOURS = 12;

    /**
     * All cached settings, grouped by group->key.
     *
     * @var array<string, array<string, string>>
     */
    protected array $settings = [];

    /**
     * Whether settings have been loaded from cache/db this request.
     */
    protected bool $loaded = false;

    /**
     * Default values for each setting group.
     * Initialized in constructor to support json_encode() calls.
     *
     * @var array<string, array<string, string|null>>
     */
    protected array $defaults;

    /**
     * Constructor — initializes defaults (must be in constructor for json_encode compat).
     */
    public function __construct()
    {
        $this->defaults = [
            'general' => [
                'platform_name' => 'ILLUVISSION',
                'logo' => null,
                'favicon' => null,
                'timezone' => 'Asia/Dubai',
                'currency' => 'aed',
                'date_format' => 'DD/MM/YYYY',
                'time_format' => '24h',
                'support_email' => 'support@illuvission.com',
                'support_phone' => '+971 4 123 4567',
            ],
            'booking' => [
                'require_admin_approval' => '1',
                'auto_approve_bookings' => '0',
                'default_slot_duration' => '60',
                'max_bookings_per_day' => '5',
                'booking_buffer_time' => '15',
                'allowed_media_types' => '["image/jpeg","image/png","application/pdf"]',
                'max_upload_size' => '10',
                'max_upload_count' => '5',
                'allow_cancellation' => '1',
                'cancellation_cutoff_hours' => '24',
                'max_ads_per_day' => '12',
                'max_campaign_days' => '90',
                'min_campaign_days' => '1',
                'max_screens_per_booking' => '50',
                'max_cities_per_booking' => '10',
                'max_files_per_upload' => '10',
                'max_file_size_kb' => '102400',
                'max_extend_minutes' => '1440',
            ],
            'media' => [
                'max_upload_files' => '10',
                'max_file_size_mb' => '100',
                'max_total_upload_size_mb' => '500',
                'allowed_image_types' => '["image/jpeg","image/png","image/gif","image/webp"]',
                'allowed_video_types' => '["video/mp4","video/mov","video/avi","video/mkv"]',
                'max_video_duration_seconds' => '300',
                'media_review_required' => '1',
            ],
            'pricing' => [
                'maximum_discount_percentage' => '50',
                'minimum_booking_amount' => '0',
                'pricing_precision' => '2',
                'max_preview_duration_hours' => '720',
                'weekend_days' => '["Sat","Sun"]',
                'first_booking_discount_enabled' => '1',
                'first_booking_discount_percentage' => '15',
                'first_booking_discount_max_amount' => '5000',
            ],
            'referral' => [
                'max_referral_discount_percentage' => '100',
                'max_referral_discount_amount' => '0',
                'max_referral_usage_per_user' => '10',
                'referral_expiry_days' => '365',
                'max_partner_commission_percentage' => '50',
                'max_commission_amount' => '100000',
            ],
            'notifications' => $this->buildNotificationDefaults(),
            'loyalty' => [
                'enable_rewards' => '1',
                'enable_redemption' => '1',
                'points_expiry_days' => '365',
                'min_points_to_redeem' => '100',
                'max_points_redeem_per_booking' => '1000',
                'reward_calculation_type' => 'percentage',
                'fixed_points' => '50',
                'spend_required_per_point' => '100',
                'redemption_point_value' => '1.00',
                'max_discount_percentage' => '50',
                'max_discount_amount' => '0',
                'allow_reward_and_referral' => '0',
                'allow_reward_and_coupon' => '1',
                'min_booking_amount' => '0',
                // Controls when Ad Credits are credited to the customer.
                // Options: 'payment_successful', 'booking_approved', 'campaign_completed'
                'reward_trigger' => 'booking_approved',
            ],
        ];
    }

    /**
     * Build notification default configs (JSON strings for each event).
     */
    protected function buildNotificationDefaults(): array
    {
        $cfg = function (bool $enabled, bool $admin, bool $user, bool $partner, bool $email, bool $inApp) {
            return json_encode([
                'enabled' => $enabled,
                'send_to_admin' => $admin,
                'send_to_user' => $user,
                'send_to_partner' => $partner,
                'channel_email' => $email,
                'channel_in_app' => $inApp,
            ]);
        };

        return [
            'booking_created_config' => $cfg(true, true, true, false, true, false),
            'booking_approved_config' => $cfg(true, true, true, false, true, false),
            'booking_rejected_config' => $cfg(true, false, true, false, true, false),
            'booking_cancelled_config' => $cfg(true, true, true, false, true, false),
            'media_uploaded_config' => $cfg(true, true, false, false, true, false),
            'media_approved_config' => $cfg(true, false, true, false, true, false),
            'media_rejected_config' => $cfg(true, false, true, false, true, false),
            'payment_received_config' => $cfg(true, true, true, false, true, false),
            'payment_failed_config' => $cfg(true, false, true, false, true, false),
            'payment_refunded_config' => $cfg(true, true, true, false, true, false),
            'referral_used_config' => $cfg(true, true, false, true, true, false),
            'commission_generated_config' => $cfg(true, true, false, true, true, false),
            'partner_created_config' => $cfg(true, true, false, false, true, false),
            'partner_approved_config' => $cfg(true, false, false, true, true, false),
            'partner_rejected_config' => $cfg(true, false, false, true, true, false),
            'user_registered_config' => $cfg(true, true, true, false, true, false),
            'contact_enquiry_submitted_config' => $cfg(true, true, false, false, true, false),
            'contact_enquiry_assigned_config' => $cfg(true, true, false, false, true, false),
            'contact_enquiry_closed_config' => $cfg(true, true, false, false, true, false),
            'contact_enquiry_auto_reply_config' => $cfg(true, false, true, false, true, false),
            'product_enquiry_submitted_config' => $cfg(true, true, false, false, true, false),
            'profile_updated_config' => $cfg(false, false, true, false, false, false),
            'admin_emails' => '[]',
            'email_notifications' => '1',
            'in_app_notifications' => '0',
        ];
    }

    /**
     * Ensure settings are loaded (once per instance).
     */
    protected function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->settings = Cache::remember(self::CACHE_KEY, now()->addHours(self::CACHE_TTL_HOURS), function () {
            $dbSettings = Setting::all()->groupBy('group')->map(function ($items) {
                return $items->pluck('value', 'key')->toArray();
            })->toArray();

            // Merge defaults with database values (db values override defaults)
            foreach ($this->defaults as $group => $defaultValues) {
                $this->settings[$group] = array_merge($defaultValues, $dbSettings[$group] ?? []);
            }

            return $this->settings;
        });

        $this->loaded = true;
    }

    /**
     * Clear the settings cache.
     * Called automatically when settings are updated.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = false;
        $this->settings = [];
    }

    /**
     * Get all settings for a specific group.
     *
     * @param string $group
     * @return array<string, mixed>
     */
    public function getGroup(string $group): array
    {
        $this->load();
        return $this->settings[$group] ?? $this->defaults[$group] ?? [];
    }

    /**
     * Get a single setting value by group and key.
     *
     * @param string $group
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $this->load();
        return $this->settings[$group][$key] ?? $default;
    }

    /**
     * Get a setting as a boolean.
     */
    public function getBool(string $group, string $key, bool $default = false): bool
    {
        $value = $this->get($group, $key, $default ? '1' : '0');
        return $value === '1' || $value === 1 || $value === true;
    }

    /**
     * Get a setting as an integer.
     */
    public function getInt(string $group, string $key, int $default = 0): int
    {
        return (int) $this->get($group, $key, $default);
    }

    /**
     * Get a setting as a float.
     */
    public function getFloat(string $group, string $key, float $default = 0.0): float
    {
        return (float) $this->get($group, $key, $default);
    }

    /**
     * Get a setting as an array (JSON decoded).
     */
    public function getArray(string $group, string $key, array $default = []): array
    {
        $value = $this->get($group, $key);
        if (is_null($value)) {
            return $default;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * Get a setting as a comma/JSON decoded list of strings.
     */
    public function getList(string $group, string $key, array $default = []): array
    {
        return $this->getArray($group, $key, $default);
    }

    /**
     * Get all settings (flattened or grouped).
     */
    public function getAll(): array
    {
        $this->load();
        return $this->settings;
    }

    /**
     * Get public-facing booking configuration for frontend consumption.
     */
    public function getPublicBookingConfig(): array
    {
        $booking = $this->getGroup('booking');
        $media = $this->getGroup('media');
        $pricing = $this->getGroup('pricing');

        return [
            'max_upload_files' => (int) ($booking['max_upload_count'] ?? $media['max_upload_files'] ?? 10),
            'max_file_size_mb' => (int) ($booking['max_upload_size'] ?? 100),
            'max_file_size_kb' => (int) ($booking['max_file_size_kb'] ?? 102400),
            'allowed_media_types' => $this->getArray('booking', 'allowed_media_types', [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'video/mp4', 'video/mov', 'video/avi', 'video/mkv',
            ]),
            'allowed_image_types' => $this->getArray('media', 'allowed_image_types'),
            'allowed_video_types' => $this->getArray('media', 'allowed_video_types'),
            'max_ads_per_day' => (int) ($booking['max_ads_per_day'] ?? 12),
            'max_campaign_days' => (int) ($booking['max_campaign_days'] ?? 90),
            'min_campaign_days' => (int) ($booking['min_campaign_days'] ?? 1),
            'max_screens_per_booking' => (int) ($booking['max_screens_per_booking'] ?? 50),
            'require_admin_approval' => $this->getBool('booking', 'require_admin_approval', true),
            'auto_approve_bookings' => $this->getBool('booking', 'auto_approve_bookings', false),
            'allow_cancellation' => $this->getBool('booking', 'allow_cancellation', true),
            'cancellation_cutoff_hours' => (int) ($booking['cancellation_cutoff_hours'] ?? 24),
            'default_slot_duration' => (int) ($booking['default_slot_duration'] ?? 60),
            'booking_buffer_time' => (int) ($booking['booking_buffer_time'] ?? 15),
            'max_video_duration_seconds' => (int) ($media['max_video_duration_seconds'] ?? 300),
            'media_review_required' => $this->getBool('media', 'media_review_required', true),
            'max_discount_percentage' => (int) ($pricing['maximum_discount_percentage'] ?? 50),
            'minimum_booking_amount' => (float) ($pricing['minimum_booking_amount'] ?? 0),
            'first_booking_discount_enabled' => $this->getBool('pricing', 'first_booking_discount_enabled', true),
            'first_booking_discount_percentage' => (float) ($pricing['first_booking_discount_percentage'] ?? 15),
            'first_booking_discount_max_amount' => (float) ($pricing['first_booking_discount_max_amount'] ?? 5000),
        ];
    }
}
