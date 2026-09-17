<?php

namespace App\Services\HardwareFulfilment\Data;

final class PackagePhotoOptimizationResult
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $sizeBytes,
        public readonly bool $wasOptimized,
    ) {}

    public function sizeKb(): int
    {
        return max(1, (int) ceil($this->sizeBytes / 1024));
    }
}
