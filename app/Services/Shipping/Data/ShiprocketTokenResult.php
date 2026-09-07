<?php

namespace App\Services\Shipping\Data;

final class ShiprocketTokenResult
{
    public function __construct(
        public readonly string $token,
        public readonly int $ttlSeconds,
    ) {}
}
