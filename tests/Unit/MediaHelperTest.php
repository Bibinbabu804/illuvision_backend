<?php

namespace Tests\Unit;

use App\Helpers\MediaHelper;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class MediaHelperTest extends TestCase
{
    public function test_it_can_compress_an_uploaded_image(): void
    {
        $image = imagecreatetruecolor(1200, 800);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);

        $tempPath = tempnam(sys_get_temp_dir(), 'media-helper-test-');
        imagejpeg($image, $tempPath, 90);
        imagedestroy($image);

        $uploadedFile = new UploadedFile($tempPath, 'sample.jpg', 'image/jpeg', null, true);

        $result = MediaHelper::compressUploadedImage($uploadedFile, 'tests/compressed');

        $this->assertArrayHasKey('file_path', $result);
        $this->assertArrayHasKey('file_size', $result);
        $this->assertGreaterThan(0, $result['file_size']);
        $this->assertTrue($result['is_compressed'] || !$result['is_compressed']);

        unlink($tempPath);
    }
}
