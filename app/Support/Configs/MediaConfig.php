<?php

namespace App\Support\Configs;

use App\Services\SettingsService;

/**
 * MediaConfig - Typed configuration object for media upload operational limits.
 *
 * All values come from the cached SettingsService.
 * No hardcoded business rules or magic strings.
 */
class MediaConfig
{
    private SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Maximum number of files that can be uploaded at once.
     */
    public function maxUploadFiles(): int
    {
        return $this->settings->getInt('media', 'max_upload_files', 10);
    }

    /**
     * Maximum file size per individual upload (in MB).
     */
    public function maxFileSizeMb(): int
    {
        return $this->settings->getInt('media', 'max_file_size_mb', 100);
    }

    /**
     * Maximum file size in KB (for validation rules).
     */
    public function maxFileSizeKb(): int
    {
        return $this->maxFileSizeMb() * 1024;
    }

    /**
     * Maximum total upload size across all files in a single request (in MB).
     */
    public function maxTotalUploadSizeMb(): int
    {
        return $this->settings->getInt('media', 'max_total_upload_size_mb', 500);
    }

    /**
     * Allowed image MIME types.
     */
    public function allowedImageTypes(): array
    {
        return $this->settings->getArray('media', 'allowed_image_types', [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ]);
    }

    /**
     * Allowed video MIME types.
     */
    public function allowedVideoTypes(): array
    {
        return $this->settings->getArray('media', 'allowed_video_types', [
            'video/mp4',
            'video/mov',
            'video/avi',
            'video/mkv',
        ]);
    }

    /**
     * Allowed image extensions string for file validation.
     */
    public function allowedImageExtensionsString(): string
    {
        return 'jpg,jpeg,png,gif,webp';
    }

    /**
     * Allowed video extensions string for file validation.
     */
    public function allowedVideoExtensionsString(): string
    {
        return 'mp4,mov,avi,mkv';
    }

    /**
     * All media extensions combined string for file validation.
     */
    public function allowedMediaExtensionsString(): string
    {
        $image = $this->allowedImageExtensionsString();
        $video = $this->allowedVideoExtensionsString();
        return $image . ',' . $video;
    }

    /**
     * Maximum video duration in seconds.
     */
    public function maxVideoDurationSeconds(): int
    {
        return $this->settings->getInt('media', 'max_video_duration_seconds', 300);
    }

    /**
     * Whether media review is required before booking approval.
     */
    public function mediaReviewRequired(): bool
    {
        return $this->settings->getBool('media', 'media_review_required', true);
    }
}
