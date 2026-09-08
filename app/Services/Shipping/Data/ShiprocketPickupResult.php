<?php

namespace App\Services\Shipping\Data;

final class ShiprocketPickupResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
        public readonly bool $alreadyQueued = false,
    ) {}

    public function isAccepted(): bool
    {
        return in_array($this->status, ['requested', 'already_requested'], true);
    }
}
