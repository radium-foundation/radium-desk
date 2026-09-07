<?php

namespace App\Services\Shipping\Data;

final class ShiprocketSearchResult
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $found,
        public readonly ?string $externalOrderId = null,
        public readonly ?string $externalShipmentId = null,
        public readonly ?string $merchantOrderId = null,
        public readonly ?string $status = null,
        public readonly ?string $awb = null,
        public readonly ?string $courierId = null,
        public readonly ?string $courierName = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}

    public function hasBindableIds(): bool
    {
        return $this->found
            && $this->externalOrderId !== null
            && trim($this->externalOrderId) !== ''
            && $this->externalShipmentId !== null
            && trim($this->externalShipmentId) !== '';
    }
}
