<?php

namespace App\Services\HardwareFulfilment;

use App\Services\HardwareFulfilment\Data\PackagePhotoOptimizationResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class PackagePhotoOptimizer
{
    public function optimize(UploadedFile $file): PackagePhotoOptimizationResult
    {
        $this->assertGdAvailable();

        $maxOutputBytes = $this->maxOutputBytes();
        $inputBytes = (int) ($file->getSize() ?: 0);
        if ($inputBytes <= 0) {
            throw ValidationException::withMessages([
                'photo' => 'Attach a package photo.',
            ]);
        }

        if ($inputBytes > $this->maxInputBytes()) {
            throw ValidationException::withMessages([
                'photo' => 'The package photo is too large to upload.',
            ]);
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'photo' => 'The package photo could not be read.',
            ]);
        }

        $info = @getimagesize($path);
        if ($info === false) {
            throw ValidationException::withMessages([
                'photo' => 'The uploaded file is not a valid image.',
            ]);
        }

        [$width, $height] = $info;
        $width = (int) $width;
        $height = (int) $height;
        $this->assertReasonableDimensions($width, $height);

        $mime = strtolower((string) ($info['mime'] ?? $file->getMimeType() ?? ''));
        if ($inputBytes <= $maxOutputBytes && $mime === 'image/jpeg' && ! $this->needsResize($width, $height)) {
            $contents = file_get_contents($path);
            if (! is_string($contents) || $contents === '') {
                throw ValidationException::withMessages([
                    'photo' => 'The package photo could not be read.',
                ]);
            }

            return new PackagePhotoOptimizationResult(
                contents: $contents,
                mimeType: 'image/jpeg',
                extension: 'jpg',
                sizeBytes: strlen($contents),
                wasOptimized: false,
            );
        }

        $source = $this->createImage($path, $mime);
        if ($source === false) {
            throw ValidationException::withMessages([
                'photo' => 'The uploaded file is not a valid image.',
            ]);
        }

        try {
            $result = $this->encodeWithinLimit($source, $width, $height, $maxOutputBytes);
        } finally {
            imagedestroy($source);
        }

        return $result;
    }

    private function assertGdAvailable(): void
    {
        if (! extension_loaded('gd') || ! function_exists('imagejpeg')) {
            throw ValidationException::withMessages([
                'photo' => 'Package photo processing is unavailable. Contact support.',
            ]);
        }
    }

    private function maxOutputBytes(): int
    {
        return max(1, (int) config('hardware_fulfilment.package_photo.max_output_kb', 150)) * 1024;
    }

    private function maxInputBytes(): int
    {
        return max(1, (int) config('hardware_fulfilment.package_photo.max_input_kb', 8192)) * 1024;
    }

    private function maxDimensionPx(): int
    {
        return max(640, (int) config('hardware_fulfilment.package_photo.max_dimension_px', 2048));
    }

    private function maxInputDimensionPx(): int
    {
        return max($this->maxDimensionPx(), (int) config('hardware_fulfilment.package_photo.max_input_dimension_px', 4096));
    }

    private function maxMegapixels(): int
    {
        return max(1, (int) config('hardware_fulfilment.package_photo.max_megapixels', 12));
    }

    private function assertReasonableDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1) {
            throw ValidationException::withMessages([
                'photo' => 'The uploaded file is not a valid image.',
            ]);
        }

        if ($width > $this->maxInputDimensionPx() || $height > $this->maxInputDimensionPx()) {
            throw ValidationException::withMessages([
                'photo' => 'The package photo dimensions are too large.',
            ]);
        }

        if (($width * $height) > ($this->maxMegapixels() * 1_000_000)) {
            throw ValidationException::withMessages([
                'photo' => 'The package photo dimensions are too large.',
            ]);
        }
    }

    private function needsResize(int $width, int $height): bool
    {
        $max = $this->maxDimensionPx();

        return $width > $max || $height > $max;
    }

    private function createImage(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function encodeWithinLimit(\GdImage $source, int $width, int $height, int $maxBytes): PackagePhotoOptimizationResult
    {
        $qualityStart = max(40, min(95, (int) config('hardware_fulfilment.package_photo.jpeg_quality_start', 85)));
        $qualityFloor = max(35, min($qualityStart, (int) config('hardware_fulfilment.package_photo.jpeg_quality_floor', 55)));
        $maxDimension = $this->maxDimensionPx();
        $scale = min(1.0, $maxDimension / max($width, $height));

        while ($scale >= 0.45) {
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $canvas = $this->resizeCanvas($source, $targetWidth, $targetHeight);

            for ($quality = $qualityStart; $quality >= $qualityFloor; $quality -= 5) {
                $contents = $this->encodeJpeg($canvas, $quality);
                if ($contents !== null && strlen($contents) <= $maxBytes) {
                    imagedestroy($canvas);

                    return new PackagePhotoOptimizationResult(
                        contents: $contents,
                        mimeType: 'image/jpeg',
                        extension: 'jpg',
                        sizeBytes: strlen($contents),
                        wasOptimized: true,
                    );
                }
            }

            imagedestroy($canvas);
            $scale -= 0.1;
        }

        throw ValidationException::withMessages([
            'photo' => sprintf(
                'Photo could not be optimized below %d KB. Please upload a clearer/smaller image.',
                (int) config('hardware_fulfilment.package_photo.max_output_kb', 150),
            ),
        ]);
    }

    private function resizeCanvas(\GdImage $source, int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw ValidationException::withMessages([
                'photo' => 'The package photo could not be processed.',
            ]);
        }

        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $canvas;
    }

    private function encodeJpeg(\GdImage $canvas, int $quality): ?string
    {
        ob_start();
        $ok = imagejpeg($canvas, null, $quality);
        $contents = ob_get_clean();

        if ($ok !== true || ! is_string($contents) || $contents === '') {
            return null;
        }

        return $contents;
    }
}
