<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [];

        // Detect which group is being updated based on the route
        $routeName = $this->route()->getName();
        $path = $this->path();

        // General settings
        $rules['platform_name'] = 'sometimes|string|max:255';
        $rules['logo'] = 'sometimes|image|mimes:jpeg,png,svg,webp|max:2048';
        $rules['favicon'] = 'sometimes|image|mimes:jpeg,png,ico|max:1024';
        $rules['timezone'] = 'sometimes|string|max:255';
        $rules['currency'] = 'sometimes|string|max:10';
        $rules['date_format'] = 'sometimes|string|max:20';
        $rules['time_format'] = 'sometimes|string|in:12h,24h';
        $rules['support_email'] = 'sometimes|email|max:255';
        $rules['support_phone'] = 'sometimes|string|max:50';

        // Booking settings
        $rules['require_admin_approval'] = 'sometimes|boolean';
        $rules['auto_approve_bookings'] = 'sometimes|boolean';
        $rules['default_slot_duration'] = 'sometimes|integer|min:15|max:1440';
        $rules['max_bookings_per_day'] = 'sometimes|integer|min:1|max:100';
        $rules['booking_buffer_time'] = 'sometimes|integer|min:0|max:120';
        $rules['allowed_media_types'] = 'sometimes|array';
        $rules['allowed_media_types.*'] = 'string';
        $rules['max_upload_size'] = 'sometimes|integer|min:1|max:100';
        $rules['max_upload_count'] = 'sometimes|integer|min:1|max:50';
        $rules['allow_cancellation'] = 'sometimes|boolean';
        $rules['cancellation_cutoff_hours'] = 'sometimes|integer|min:1|max:168';
        // Additional booking operational limits
        $rules['max_ads_per_day'] = 'sometimes|integer|min:1|max:1000';
        $rules['max_campaign_days'] = 'sometimes|integer|min:1|max:365';
        $rules['min_campaign_days'] = 'sometimes|integer|min:1|max:365';
        $rules['max_screens_per_booking'] = 'sometimes|integer|min:1|max:500';
        $rules['max_cities_per_booking'] = 'sometimes|integer|min:1|max:100';
        $rules['max_files_per_upload'] = 'sometimes|integer|min:1|max:50';
        $rules['max_file_size_kb'] = 'sometimes|integer|min:1|max:1048576';
        $rules['max_extend_minutes'] = 'sometimes|integer|min:1|max:1440';

        // Media settings
        $rules['max_upload_files'] = 'sometimes|integer|min:1|max:100';
        $rules['max_file_size_mb'] = 'sometimes|integer|min:1|max:1024';
        $rules['max_total_upload_size_mb'] = 'sometimes|integer|min:1|max:10240';
        $rules['allowed_image_types'] = 'sometimes|array';
        $rules['allowed_image_types.*'] = 'string';
        $rules['allowed_video_types'] = 'sometimes|array';
        $rules['allowed_video_types.*'] = 'string';
        $rules['max_video_duration_seconds'] = 'sometimes|integer|min:1|max:3600';
        $rules['media_review_required'] = 'sometimes|boolean';

        // Pricing settings
        $rules['maximum_discount_percentage'] = 'sometimes|integer|min:0|max:100';
        $rules['minimum_booking_amount'] = 'sometimes|numeric|min:0';
        $rules['pricing_precision'] = 'sometimes|integer|min:0|max:6';
        $rules['max_preview_duration_hours'] = 'sometimes|integer|min:1|max:8760';
        $rules['weekend_days'] = 'sometimes|array';
        $rules['weekend_days.*'] = 'string|in:Sat,Sun,Mon,Tue,Wed,Thu,Fri';

        // First Booking Discount settings
        $rules['first_booking_discount_enabled'] = 'sometimes|boolean';
        $rules['first_booking_discount_percentage'] = 'sometimes|numeric|min:0|max:100';
        $rules['first_booking_discount_max_amount'] = 'sometimes|numeric|min:0';

        // Referral settings
        $rules['max_referral_discount_percentage'] = 'sometimes|integer|min:0|max:100';
        $rules['max_referral_usage_per_user'] = 'sometimes|integer|min:1|max:1000';
        $rules['referral_expiry_days'] = 'sometimes|integer|min:1|max:3650';
        $rules['max_partner_commission_percentage'] = 'sometimes|integer|min:0|max:100';
        $rules['max_commission_amount'] = 'sometimes|numeric|min:0';

        // Notification settings — event configs (JSON objects)
        $rules['booking_created_config'] = 'sometimes|array';
        $rules['booking_approved_config'] = 'sometimes|array';
        $rules['booking_rejected_config'] = 'sometimes|array';
        $rules['booking_cancelled_config'] = 'sometimes|array';
        $rules['media_uploaded_config'] = 'sometimes|array';
        $rules['media_approved_config'] = 'sometimes|array';
        $rules['media_rejected_config'] = 'sometimes|array';
        $rules['payment_received_config'] = 'sometimes|array';
        $rules['payment_failed_config'] = 'sometimes|array';
        $rules['payment_refunded_config'] = 'sometimes|array';
        $rules['referral_used_config'] = 'sometimes|array';
        $rules['commission_generated_config'] = 'sometimes|array';
        $rules['partner_created_config'] = 'sometimes|array';
        $rules['user_registered_config'] = 'sometimes|array';
        $rules['profile_updated_config'] = 'sometimes|array';
        // Admin notification recipient emails
        $rules['admin_emails'] = 'sometimes|array';
        $rules['admin_emails.*'] = 'email';

        // Backward-compatible channel toggles
        $rules['email_notifications'] = 'sometimes|boolean';
        $rules['in_app_notifications'] = 'sometimes|boolean';

        // Loyalty settings
        $rules['enable_rewards'] = 'sometimes|boolean';
        $rules['enable_redemption'] = 'sometimes|boolean';
        $rules['points_expiry_days'] = 'sometimes|integer|min:1';
        $rules['min_points_to_redeem'] = 'sometimes|integer|min:0';
        $rules['max_points_redeem_per_booking'] = 'sometimes|integer|min:0';
        $rules['reward_calculation_type'] = 'sometimes|string|in:percentage,fixed';
        $rules['fixed_points'] = 'sometimes|integer|min:0';
        $rules['spend_required_per_point'] = 'sometimes|integer|min:1';
        $rules['redemption_point_value'] = 'sometimes|numeric|min:0.01';
        $rules['max_discount_percentage'] = 'sometimes|integer|min:0|max:100';
        $rules['max_discount_amount'] = 'sometimes|numeric|min:0';
        $rules['allow_reward_and_referral'] = 'sometimes|boolean';
        $rules['allow_reward_and_coupon'] = 'sometimes|boolean';
        $rules['min_booking_amount'] = 'sometimes|numeric|min:0';

        return $rules;
    }
}
