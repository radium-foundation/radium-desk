<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportOrderContext
{
    public function __construct(
        public readonly ?string $orderDate,
        public readonly ?string $orderId,
    ) {}
}
