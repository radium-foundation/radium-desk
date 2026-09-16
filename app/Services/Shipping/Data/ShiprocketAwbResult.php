<?php

namespace App\Services\Shipping\Data;

use App\Services\Shipping\ShiprocketAwbAssignmentRejection;

final class ShiprocketAwbResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $awb = null,
        public readonly ?string $courierId = null,
        public readonly ?string $courierName = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}

    public function isCourierNotServiceableRejection(): bool
    {
        return ShiprocketAwbAssignmentRejection::isDefinitiveNoAwbAssignment($this);
    }
}
