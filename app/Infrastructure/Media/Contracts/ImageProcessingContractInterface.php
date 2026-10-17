<?php

namespace App\Infrastructure\Media\Contracts;

/**
 * Image encoding port for the media layer.
 *
 * Deliberately expressed in terms of bytes rather than GD handles so that the
 * contract does not leak the underlying image extension. App\Helpers\MediaHelper
 * owns the compression *policy* (the 70KB target and the quality ladder); this
 * port only owns the capability and the encoding step.
 */
interface ImageProcessingContractInterface
{
    /**
     * Whether an image encoder is available in this runtime.
     *
     * MediaHelper falls back to copying the upload untouched when this is false.
     */
    public function isAvailable(): bool;

    /**
     * Re-encode arbitrary image bytes as WebP at the given quality.
     *
     * Returns null when the input cannot be decoded as an image, which lets the
     * caller distinguish "encoder missing" from "not actually an image".
     */
    public function encodeWebp(string $binary, int $quality): ?string;
}
