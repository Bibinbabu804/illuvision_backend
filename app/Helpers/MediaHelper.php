<?php

namespace App\Helpers;

use App\Infrastructure\Media\Contracts\ImageProcessingContractInterface;
use App\Infrastructure\Media\GdImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class MediaHelper
{
    /**
     * Compress an uploaded image.
     *
     * The compression policy (70KB target, quality ladder from 80 down to 20)
     * lives here. The encoding step itself is delegated to
     * ImageProcessingContractInterface so the GD calls are not spread across
     * application code.
     *
     * This remains a static helper for backward compatibility, so the processor
     * is resolved defensively: an explicitly supplied instance wins, then the
     * container binding, then a directly constructed default. The last fallback
     * matters because this class is called statically from unit tests that run
     * without a booted application.
     */
    public static function compressUploadedImage(
        UploadedFile $file,
        string $path,
        ?ImageProcessingContractInterface $imageProcessor = null
    ): array {
        $imageProcessor ??= self::resolveImageProcessor();

        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();
        $originalSize = $file->getSize();

        // Target size of 70KB
        $targetSizeKb = 70;
        $quality = 80;

        // Determine output directory path safely inside Laravel's public storage disk
        $dir = $path;
        if (function_exists('app') && app()->has('path.public') && function_exists('storage_path')) {
            $dir = storage_path('app/public/'.$path);
        }

        // If it is not an image or the encoder is unavailable, copy as-is
        if (! str_starts_with($mimeType, 'image/') || ! $imageProcessor->isAvailable()) {
            return self::copyAsIs($file, $dir, $path, $extension, $originalSize);
        }

        $imageContent = file_get_contents($file->getRealPath());

        // A null result means the bytes could not be decoded as an image, which
        // the original implementation also treated as "copy as-is".
        $webpData = $imageContent === false
            ? null
            : self::encodeWithinBudget($imageContent, $imageProcessor, $targetSizeKb, $quality);

        if ($webpData === null) {
            return self::copyAsIs($file, $dir, $path, $extension, $originalSize);
        }

        [$webpData, $isCompressed] = $webpData;

        $fileName = time().'_'.Str::random(10).'.webp';

        @mkdir($dir, 0755, true);
        $fullPath = rtrim($dir, '/').'/'.$fileName;
        file_put_contents($fullPath, $webpData);

        return [
            'file_path' => rtrim($path, '/').'/'.$fileName,
            'file_size' => strlen($webpData),
            'is_compressed' => $isCompressed,
        ];
    }

    /**
     * Resolve the image processor without hard-depending on a booted container.
     */
    protected static function resolveImageProcessor(): ImageProcessingContractInterface
    {
        if (function_exists('app') && app()->bound(ImageProcessingContractInterface::class)) {
            return app(ImageProcessingContractInterface::class);
        }

        return new GdImageProcessor;
    }

    /**
     * Step the quality down until the encoded image fits the target budget.
     *
     * The second tuple element mirrors the original implementation: it is true
     * only when the ladder actually stepped down, i.e. the first attempt at
     * quality 80 missed the budget.
     *
     * @return array{0: string, 1: bool}|null null when the encoder produced nothing
     */
    protected static function encodeWithinBudget(
        string $binary,
        ImageProcessingContractInterface $processor,
        int $targetSizeKb,
        int $quality
    ): ?array {
        $encoded = null;
        $currentQuality = $quality;
        $steppedDown = false;

        do {
            $encoded = $processor->encodeWebp($binary, $currentQuality);

            if ($encoded === null) {
                return null;
            }

            $sizeKb = strlen($encoded) / 1024;

            if ($sizeKb <= $targetSizeKb || $currentQuality <= 30) {
                break;
            }

            $currentQuality -= 10;
            $steppedDown = true;
        } while ($currentQuality >= 20);

        return [$encoded, $steppedDown];
    }

    /**
     * Fallback path: store the upload byte-for-byte with its original extension.
     */
    protected static function copyAsIs(
        UploadedFile $file,
        string $dir,
        string $path,
        string $extension,
        int $originalSize
    ): array {
        $fileName = time().'_'.Str::random(10).'.'.$extension;
        @mkdir($dir, 0755, true);
        $fullPath = rtrim($dir, '/').'/'.$fileName;
        copy($file->getRealPath(), $fullPath);

        return [
            'file_path' => rtrim($path, '/').'/'.$fileName,
            'file_size' => $originalSize,
            'is_compressed' => false,
        ];
    }
}
