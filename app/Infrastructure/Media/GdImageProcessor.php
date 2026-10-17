<?php

namespace App\Infrastructure\Media;

use App\Infrastructure\Media\Contracts\ImageProcessingContractInterface;
use GdImage;

/**
 * GD-backed image encoder.
 *
 * The only class in the media layer that calls the GD functions. The `null`
 * return for undecodable input is intentional: the original helper treated a
 * failed imagecreatefromstring() as "copy the file as-is" rather than failing.
 */
class GdImageProcessor implements ImageProcessingContractInterface
{
    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * {@inheritdoc}
     */
    public function encodeWebp(string $binary, int $quality): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $image = @imagecreatefromstring($binary);

        if (! $image instanceof GdImage) {
            return null;
        }

        ob_start();
        imagewebp($image, null, $quality);
        $encoded = ob_get_clean();

        imagedestroy($image);

        return $encoded === false ? null : $encoded;
    }
}
