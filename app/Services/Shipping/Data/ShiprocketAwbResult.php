<?php

namespace App\Services\Shipping\Data;

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
}
