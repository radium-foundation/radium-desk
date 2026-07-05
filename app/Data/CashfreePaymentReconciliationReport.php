<?php

namespace App\Data;

readonly class CashfreePaymentReconciliationReport
{
    /**
     * @param  list<CashfreeMissingPaidOrderRecord>  $missingOrders
     */
    public function __construct(
        public int $successfulCashfreePayments,
        public int $deskOrders,
        public int $missingOrdersCount,
        public int $failedProcessing,
        public int $paidWithoutDeskOrderCount,
        public array $missingOrders,
    ) {}
}
