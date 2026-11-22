<?php

namespace App\Data\Orders;

final readonly class OrderPaymentBreakdown
{
    public function __construct(
        public float $orderTotal,
        public float $walletTender,
        public float $externalTender,
        public float $alreadyRefunded,
        public float $maximumRefundable,
        public bool $splitTender,
    ) {}
}
