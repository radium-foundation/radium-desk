<?php

namespace App\Data;

readonly class CashfreePaymentReconciliationScalars
{
    public function __construct(
        public int $successfulCashfreePayments,
        public int $deskOrders,
        public int $missingOrdersCount,
        public int $failedProcessing,
    ) {}
}
