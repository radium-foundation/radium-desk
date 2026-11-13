<?php

namespace App\Services\Oem\Morpho\Data;

final class MorphoDeviceRegistrationResult
{
    public function __construct(
        public readonly MorphoOutcome $outcome,
        public readonly string $normalizedSerial,
        public readonly ?string $deviceType = null,
        public readonly ?string $statusCode = null,
        public readonly ?string $statusMessage = null,
    ) {}
}
