<?php

namespace App\Services\Oem\Morpho\Data;

final class MorphoOrderExpiryResult
{
    /**
     * @param  list<array{order_number: ?string, serial: ?string, whitelist_date: ?string, expiry_date: ?string, years: ?string}>  $rows
     */
    public function __construct(
        public readonly MorphoOutcome $outcome,
        public readonly string $normalizedSerial,
        public readonly array $rows = [],
        public readonly ?string $message = null,
    ) {}
}
