<?php

namespace App\Services\Shipping\Data;

final class ShiprocketDocumentResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $url = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}
}
