<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (is_array($this->resource)) {
            $data = $this->resource;

            // Cast boolean-like values
            $booleanKeys = [
                'require_admin_approval', 'auto_approve_bookings', 'allow_cancellation',
                'new_booking_created', 'booking_cancelled', 'payment_received',
                'screen_offline_alert', 'booking_approved', 'booking_rejected',
                'booking_reminder', 'campaign_expired', 'email_notifications',
                'in_app_notifications', 'media_review_required',
                'first_booking_discount_enabled',
            ];

            foreach ($data as $key => $value) {
                if (in_array($key, $booleanKeys)) {
                    $data[$key] = $value === '1' || $value === 1 || $value === true;
                }
            }

            // Cast integer-like values
            $integerKeys = [
                'default_slot_duration', 'max_bookings_per_day',
                'booking_buffer_time', 'max_upload_size', 'max_upload_count',
                'cancellation_cutoff_hours',
                'max_ads_per_day', 'max_campaign_days', 'min_campaign_days',
                'max_screens_per_booking', 'max_cities_per_booking',
                'max_files_per_upload', 'max_file_size_kb', 'max_extend_minutes',
                // Media group
                'max_upload_files', 'max_file_size_mb', 'max_total_upload_size_mb',
                'max_video_duration_seconds',
                // Pricing group
                'maximum_discount_percentage', 'pricing_precision',
                'max_preview_duration_hours',
                // Referral group
                'max_referral_discount_percentage', 'max_referral_usage_per_user',
                'referral_expiry_days', 'max_partner_commission_percentage',
                'max_commission_amount',
            ];

            foreach ($data as $key => $value) {
                if (in_array($key, $integerKeys)) {
                    $data[$key] = (int) $value;
                }
            }

            // Float values
            $floatKeys = [
                'minimum_booking_amount', 'max_referral_discount_amount',
                'first_booking_discount_percentage', 'first_booking_discount_max_amount',
            ];

            foreach ($data as $key => $value) {
                if (in_array($key, $floatKeys)) {
                    $data[$key] = (float) $value;
                }
            }

            // Decode JSON fields
            $jsonKeys = [
                'allowed_media_types',
                'allowed_image_types',
                'allowed_video_types',
                'weekend_days',
                // Notification event configs
                'booking_created_config',
                'booking_approved_config',
                'booking_rejected_config',
                'booking_cancelled_config',
                'media_uploaded_config',
                'media_approved_config',
                'media_rejected_config',
                'payment_received_config',
                'payment_failed_config',
                'payment_refunded_config',
                'referral_used_config',
                'commission_generated_config',
                'partner_created_config',
                'user_registered_config',
                'profile_updated_config',
                'admin_emails',
            ];

            foreach ($data as $key => $value) {
                if (in_array($key, $jsonKeys) && is_string($value)) {
                    $decoded = json_decode($value, true);
                    $data[$key] = is_array($decoded) ? $decoded : [];
                }
            }

            return $data;
        }

        return parent::toArray($request);
    }
}
