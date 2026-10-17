<?php

namespace App\Services;
use App\Infrastructure\Storage\Contracts\StorageContractInterface;
use App\Support\Configs\BookingConfig;
use App\Support\Configs\MediaConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class MediaService
{
    protected MediaConfig $mediaConfig;

    protected BookingConfig $bookingConfig;

    public function __construct(
        MediaConfig $mediaConfig,
        BookingConfig $bookingConfig,
        protected StorageContractInterface $storage,
    ) {
        $this->mediaConfig = $mediaConfig;
        $this->bookingConfig = $bookingConfig;
    }

    /**
     * Upload a media file and return the stored path.
     */
    public function upload(UploadedFile $file, string $path = 'bookings/media'): array
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();

        $fileName = time().'_'.Str::random(10).'.'.$extension;
        $storedPath = $this->storage->storeAs($file, $path, $fileName);

        $fileType = str_starts_with($mimeType, 'video') ? 'video' : 'image';

        return [
            'file_path' => $storedPath,
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'original_name' => $originalName,
            'url' => $this->storage->url($storedPath),
        ];
    }

    /**
     * Delete a media file from storage.
     */
    public function delete(string $filePath): bool
    {
        if ($this->storage->exists($filePath)) {
            return $this->storage->delete($filePath);
        }

        return false;
    }

    /**
     * Generate validation rules for booking media uploads.
     *
     * Uses MediaConfig and BookingConfig values instead of hardcoded limits.
     *
     * @param  array  $rules  Additional rules to merge
     */
    public function validate(array $rules = []): array
    {
        $mimeTypes = $this->mediaConfig->allowedMediaExtensionsString();
        $maxSizeKb = $this->mediaConfig->maxFileSizeKb();

        return array_merge([
            'file' => 'required|file|mimes:'.$mimeTypes.'|max:'.$maxSizeKb,
        ], $rules);
    }

    /**
     * Generate validation rules for batch media upload (multiple files).
     */
    public function batchValidationRules(): array
    {
        $maxFiles = $this->bookingConfig->maxFilesPerUpload();
        $mimeTypes = $this->mediaConfig->allowedMediaExtensionsString();
        $maxSizeKb = $this->mediaConfig->maxFileSizeKb();

        return [
            'files' => 'required|array|min:1|max:'.$maxFiles,
            'files.*' => 'required|file|mimes:'.$mimeTypes.'|max:'.$maxSizeKb,
        ];
    }
}
