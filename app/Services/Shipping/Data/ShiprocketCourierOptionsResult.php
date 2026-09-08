<?php

namespace App\Services\Shipping\Data;

final class ShiprocketCourierOptionsResult
{
    /**
     * @param  list<ShiprocketCourierOption>  $options
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly array $options = [],
        public readonly ?string $recommendedCourierId = null,
        public readonly bool $recommendationReturned = false,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}
}
