<?php

namespace App\Services\ReturnExchange\Data;

final class ReturnedHardwareEligibleRefundAmount
{
    public function __construct(
        public readonly string $amount,
        public readonly bool $isPartialRelativeToOrderPayment,
    ) {}
}
