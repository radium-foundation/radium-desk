<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\PackagePhotoOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PackagePhotoOptimizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for package photo optimization tests.');
        }
    }

    public function test_small_jpeg_passes_through_without_recompression(): void
    {
        $file = $this->jpegUpload(400, 300, 70);

        $result = app(PackagePhotoOptimizer::class)->optimize($file);

        $this->assertFalse($result->wasOptimized);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertLessThanOrEqual(150 * 1024, $result->sizeBytes);
        $this->assertSame($file->get(), $result->contents);
    }

    public function test_large_photo_is_optimized_to_configured_maximum(): void
    {
        $file = $this->jpegUpload(2400, 1800, 95);

        $result = app(PackagePhotoOptimizer::class)->optimize($file);

        $this->assertTrue($result->wasOptimized);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertLessThanOrEqual(150 * 1024, $result->sizeBytes);
        $this->assertGreaterThan(0, strlen($result->contents));
        $this->assertSame(1, @getimagesizefromstring($result->contents) !== false ? 1 : 0);
    }

    public function test_oversized_input_is_rejected_before_processing(): void
    {
        config()->set('hardware_fulfilment.package_photo.max_input_kb', 1);

        $file = $this->jpegUpload(800, 600, 90);

        $this->expectException(ValidationException::class);
        app(PackagePhotoOptimizer::class)->optimize($file);
    }

    public function test_invalid_upload_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->expectException(ValidationException::class);
        app(PackagePhotoOptimizer::class)->optimize($file);
    }

    private function jpegUpload(int $width, int $height, int $quality): UploadedFile
    {
        $canvas = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($canvas, 220, 220, 220);
        imagefill($canvas, 0, 0, $background);
        $ink = imagecolorallocate($canvas, 20, 20, 20);
        imagestring($canvas, 5, 20, 20, 'PACKAGE LABEL 12345', $ink);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $contents = (string) ob_get_clean();
        imagedestroy($canvas);

        $path = tempnam(sys_get_temp_dir(), 'pkg-photo-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'package.jpg', 'image/jpeg', null, true);
    }
}
