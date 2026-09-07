<?php

namespace App\Services\Shipping\Data;

final class ShiprocketCreateOrderResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $externalOrderId = null,
        public readonly ?string $externalShipmentId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $awb = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}
}
