<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Http\Resources\SettingsResource;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    protected SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Public-facing booking configuration for frontend consumption.
     * No auth required - returns operational limits and validation rules.
     */
    public function publicBookingConfig(): JsonResponse
    {
        $config = $this->settingsService->getPublicBookingConfig();
        return $this->successResponse($config, 'Public booking configuration retrieved');
    }

    // ─── General Settings ─────────────────────────────────────

    public function getGeneral(): JsonResponse
    {
        $settings = Setting::getGroup('general');

        $defaults = [
            'platform_name' => 'ILLUVISSION',
            'logo' => null,
            'favicon' => null,
            'timezone' => 'Asia/Dubai',
            'currency' => 'aed',
            'date_format' => 'DD/MM/YYYY',
            'time_format' => '24h',
            'support_email' => 'support@illuvission.com',
            'support_phone' => '+971 4 123 4567',
        ];

        $data = array_merge($defaults, $settings);

        return $this->successResponse(new SettingsResource($data), 'General settings retrieved');
    }

    public function updateGeneral(SettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('settings', $fileName, 'public');
            $validated['logo'] = $path;
        }

        if ($request->hasFile('favicon')) {
            $file = $request->file('favicon');
            $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('settings', $fileName, 'public');
            $validated['favicon'] = $path;
        }

        Setting::setGroup('general', $validated);
        $this->settingsService->clearCache(); // Invalidate cache
        $settings = Setting::getGroup('general');

        return $this->successResponse(new SettingsResource($settings), 'General settings updated');
    }

    // ─── Booking Settings ─────────────────────────────────────

    public function getBooking(): JsonResponse
    {
        $settings = Setting::getGroup('booking');

        $defaults = [
            'require_admin_approval' => '1',
            'auto_approve_bookings' => '0',
            'default_slot_duration' => '60',
            'max_bookings_per_day' => '5',
            'booking_buffer_time' => '15',
            'allowed_media_types' => json_encode(['image/jpeg', 'image/png', 'application/pdf']),
            'max_upload_size' => '10',
            'max_upload_count' => '5',
            'allow_cancellation' => '1',
            'cancellation_cutoff_hours' => '24',
            // Additional operational limits
            'max_ads_per_day' => '12',
            'max_campaign_days' => '90',
            'min_campaign_days' => '1',
            'max_screens_per_booking' => '50',
            'max_cities_per_booking' => '10',
            'max_files_per_upload' => '10',
            'max_file_size_kb' => '102400',
            'max_extend_minutes' => '1440',
        ];

        $data = array_merge($defaults, $settings);

        return $this->successResponse(new SettingsResource($data), 'Booking settings retrieved');
    }

    public function updateBooking(SettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['allowed_media_types']) && is_array($validated['allowed_media_types'])) {
            $validated['allowed_media_types'] = json_encode($validated['allowed_media_types']);
        }

        Setting::setGroup('booking', $validated);
        $this->settingsService->clearCache(); // Invalidate cache
        $settings = Setting::getGroup('booking');

        return $this->successResponse(new SettingsResource($settings), 'Booking settings updated');
    }

    // ─── Media Settings ───────────────────────────────────────

    public function getMedia(): JsonResponse
    {
        $settings = Setting::getGroup('media');

        $defaults = [
            'max_upload_files' => '10',
            'max_file_size_mb' => '100',
            'max_total_upload_size_mb' => '500',
            'allowed_image_types' => json_encode(['image/jpeg', 'image/png', 'image/gif', 'image/webp']),
            'allowed_video_types' => json_encode(['video/mp4', 'video/mov', 'video/avi', 'video/mkv']),
            'max_video_duration_seconds' => '300',
            'media_review_required' => '1',
        ];

        $data = array_merge($defaults, $settings);

        return $this->successResponse(new SettingsResource($data), 'Media settings retrieved');
    }

    public function updateMedia(SettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        foreach (['allowed_image_types', 'allowed_video_types'] as $jsonField) {
            if (isset($validated[$jsonField]) && is_array($validated[$jsonField])) {
                $validated[$jsonField] = json_encode($validated[$jsonField]);
            }
        }

        Setting::setGroup('media', $validated);
        $this->settingsService->clearCache();

        $settings = Setting::getGroup('media');
        return $this->successResponse(new SettingsResource($settings), 'Media settings updated');
    }

    // ─── Pricing Settings ─────────────────────────────────────

    public function getPricing(): JsonResponse
    {
        $settings = Setting::getGroup('pricing');

        $defaults = [
            'maximum_discount_percentage' => '50',
            'minimum_booking_amount' => '0',
            'pricing_precision' => '2',
            'max_preview_duration_hours' => '720',
            'weekend_days' => json_encode(['Sat', 'Sun']),
            'first_booking_discount_enabled' => '1',
            'first_booking_discount_percentage' => '15',
            'first_booking_discount_max_amount' => '5000',
        ];

        $data = array_merge($defaults, $settings);

        return $this->successResponse(new SettingsResource($data), 'Pricing settings retrieved');
    }

    public function updatePricing(SettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['weekend_days']) && is_array($validated['weekend_days'])) {
            $validated['weekend_days'] = json_encode($validated['weekend_days']);
        }

        Setting::setGroup('pricing', $validated);
        $this->settingsService->clearCache();

        $settings = Setting::getGroup('pricing');
        return $this->successResponse(new SettingsResource($settings), 'Pricing settings updated');
    }

    // ─── First Booking Discount Settings ─────────────────────

    public function getFirstBookingDiscount(): JsonResponse
    {
        $settings = Setting::getGroup('pricing');

        $defaults = [
            'first_booking_discount_enabled' => '1',
            'first_booking_discount_percentage' => '15',
            'first_booking_discount_max_amount' => '5000',
        ];

        $data = array_merge($defaults, $settings);

        return $this->successResponse(new SettingsResource($data), 'First booking discount settings retrieved');
    }

    public function updateFirstBookingDiscount(SettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        Setting::setGroup('pricing', $validated);
        $this->settingsService->clearCache();

        $settings = Setting::getGroup('pricing');
        return $this->successResponse(new SettingsResource($settings), 'First booking discount settings updated');
    }

    // ─── Loyalty Settings ─────────────────────────────────────

    public function getLoyalty(): JsonResponse
    {
        $settings = Setting::getGroup('loyalty');

        $defaults = [
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
            'reward_trigger' => 'booking_approved',
        ];

        $data = array_merge($defaults, $settings);

        return $this->successResponse(new SettingsResource($data), 'Loyalty settings retrieved');
    }

    public function updateLoyalty(SettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        Setting::setGroup('loyalty', $validated);
        $this->settingsService->clearCache();

        $settings = Setting::getGroup('loyalty');
        return $this->successResponse(new SettingsResource($settings), 'Loyalty settings updated');
    }

    // ─── Referral Settings ────────────────────────────────────

    public function getReferral(): JsonResponse
    {
        $settings = Setting::getGroup('referral');

        $defaults = [
            'max_referral_discount_percentage' => '100',
            'max_referral_usage_per_user' => '10',
            'referral_expiry_days' => '365',
            'max_partner_commission_percentage' => '50',
            'max_commission_amount' => '100000',
        ];

        $data = array_merge($defaults, $settings);

        return $this->successResponse(new SettingsResource($data), 'Referral settings retrieved');
    }

    public function updateReferral(SettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        Setting::setGroup('referral', $validated);
        $this->settingsService->clearCache();

        $settings = Setting::getGroup('referral');
        return $this->successResponse(new SettingsResource($settings), 'Referral settings updated');
    }

    // ─── Notification Settings ────────────────────────────────

    public function getNotifications(): JsonResponse
    {
        $settings = Setting::getGroup('notifications');

        $defaults = [
            // Event-based notification configs
            'booking_created_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'booking_approved_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'booking_rejected_config' => json_encode(['enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'booking_cancelled_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'media_uploaded_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'media_approved_config' => json_encode(['enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'media_rejected_config' => json_encode(['enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'payment_received_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'payment_failed_config' => json_encode(['enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'payment_refunded_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'referral_used_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => true, 'channel_email' => true, 'channel_in_app' => false]),
            'commission_generated_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => true, 'channel_email' => true, 'channel_in_app' => false]),
            'partner_created_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'user_registered_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'profile_updated_config' => json_encode(['enabled' => false, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => false, 'channel_in_app' => false]),
            'contact_enquiry_submitted_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'contact_enquiry_assigned_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'contact_enquiry_closed_config' => json_encode(['enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'contact_enquiry_auto_reply_config' => json_encode(['enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false]),
            'admin_emails' => json_encode([]),
            // Backward-compatible global channel toggles
            'email_notifications' => '1',
            'in_app_notifications' => '0',
        ];

        $data = array_merge($defaults, $settings);

        return $this->successResponse(new SettingsResource($data), 'Notification settings retrieved');
    }

    public function updateNotifications(SettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // JSON-encode any config keys that arrive as arrays
        foreach ($validated as $key => $value) {
            if (str_ends_with($key, '_config') && is_array($value)) {
                $validated[$key] = json_encode($value);
            }
        }

        Setting::setGroup('notifications', $validated);
        $this->settingsService->clearCache(); // Invalidate cache
        $settings = Setting::getGroup('notifications');

        return $this->successResponse(new SettingsResource($settings), 'Notification settings updated');
    }
}
