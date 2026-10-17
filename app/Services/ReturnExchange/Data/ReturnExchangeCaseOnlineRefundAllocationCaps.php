<?php

namespace App\Services\ReturnExchange\Data;

final class ReturnExchangeCaseOnlineRefundAllocationCaps
{
    public function __construct(
        public readonly string $returnedLineCap,
        public readonly string $orderPaidTotal,
        public readonly string $priorRefundsTotal,
        public readonly string $maxAllocatableAmount,
        public readonly string $currency,
    ) {}
}
