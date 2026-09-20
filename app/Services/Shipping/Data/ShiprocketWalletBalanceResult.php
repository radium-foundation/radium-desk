<?php

namespace App\Services\Shipping\Data;

final class ShiprocketWalletBalanceResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $balanceAmount = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
        public readonly ?string $failureKind = null,
    ) {}
}
