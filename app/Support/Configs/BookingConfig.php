<?php

namespace App\Support\Configs;

use App\Services\SettingsService;

/**
 * BookingConfig - Typed configuration object for booking operational limits.
 *
 * All values come from the cached SettingsService.
 * No hardcoded business rules or magic strings.
 */
class BookingConfig
{
    private SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Maximum advertisements per screen per day.
     */
    public function maxAdsPerDay(): int
    {
        return $this->settings->getInt('booking', 'max_ads_per_day', 12);
    }

    /**
     * Maximum campaign duration in days.
     */
    public function maxCampaignDays(): int
    {
        return $this->settings->getInt('booking', 'max_campaign_days', 90);
    }

    /**
     * Minimum campaign duration in days.
     */
    public function minCampaignDays(): int
    {
        return $this->settings->getInt('booking', 'min_campaign_days', 1);
    }

    /**
     * Maximum screens that can be selected per booking.
     */
    public function maxScreensPerBooking(): int
    {
        return $this->settings->getInt('booking', 'max_screens_per_booking', 50);
    }

    /**
     * Maximum number of bookings allowed per day across all screens.
     */
    public function maxBookingsPerDay(): int
    {
        return $this->settings->getInt('booking', 'max_bookings_per_day', 5);
    }

    /**
     * Buffer time between consecutive booking slots (in minutes).
     */
    public function bookingBufferTime(): int
    {
        return $this->settings->getInt('booking', 'booking_buffer_time', 15);
    }

    /**
     * Whether admin approval is required for bookings.
     */
    public function requireAdminApproval(): bool
    {
        return $this->settings->getBool('booking', 'require_admin_approval', true);
    }

    /**
     * Whether bookings are auto-approved for verified clients.
     */
    public function autoApproveBookings(): bool
    {
        return $this->settings->getBool('booking', 'auto_approve_bookings', false);
    }

    /**
     * Whether cancellation is allowed.
     */
    public function allowCancellation(): bool
    {
        return $this->settings->getBool('booking', 'allow_cancellation', true);
    }

    /**
     * Hours before start time after which cancellation is not allowed.
     */
    public function cancellationCutoffHours(): int
    {
        return $this->settings->getInt('booking', 'cancellation_cutoff_hours', 24);
    }

    /**
     * Default slot duration in minutes.
     */
    public function defaultSlotDuration(): int
    {
        return $this->settings->getInt('booking', 'default_slot_duration', 60);
    }

    /**
     * Maximum files per upload request.
     */
    public function maxFilesPerUpload(): int
    {
        return $this->settings->getInt('booking', 'max_files_per_upload', 10);
    }

    /**
     * Maximum allowed file size per upload (in KB).
     */
    public function maxFileSizeKb(): int
    {
        return $this->settings->getInt('booking', 'max_file_size_kb', 102400);
    }

    /**
     * Maximum extend session minutes.
     */
    public function maxExtendMinutes(): int
    {
        return $this->settings->getInt('booking', 'max_extend_minutes', 1440);
    }

    /**
     * Maximum upload count per booking.
     */
    public function maxUploadCount(): int
    {
        return $this->settings->getInt('booking', 'max_upload_count', 5);
    }

    /**
     * Maximum upload size per file (in MB).
     */
    public function maxUploadSizeMb(): int
    {
        return $this->settings->getInt('booking', 'max_upload_size', 10);
    }

    /**
     * Allowed media types for booking uploads.
     */
    public function allowedMediaTypes(): array
    {
        return $this->settings->getArray('booking', 'allowed_media_types', [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'video/mp4', 'video/mov',
        ]);
    }

    /**
     * Get the media mime types string for file validation rules.
     */
    public function allowedMimeTypesString(): string
    {
        $types = $this->allowedMediaTypes();
        $mimeToExt = [
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/mov' => 'mov',
            'video/avi' => 'avi',
            'video/mkv' => 'mkv',
            'application/pdf' => 'pdf',
        ];

        $extensions = [];
        foreach ($types as $mime) {
            if (isset($mimeToExt[$mime])) {
                $extensions[] = $mimeToExt[$mime];
            }
        }

        return implode(',', $extensions);
    }
}
