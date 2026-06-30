<?php

namespace App\Data;

readonly class OrderIdentityRepairSummary
{
    /**
     * @param  list<string>  $repairedOrderIds
     * @param  list<OrderIdentityRepairFailure>  $failedOrders
     */
    public function __construct(
        public int $ordersScanned,
        public int $ordersRepaired,
        public int $ordersSkipped,
        public int $ordersAlreadyValid,
        public int $ordersFailed,
        public int $assignmentsEscalated,
        public int $assignmentsToAgent,
        public int $assignmentsUnchanged,
        public array $repairedOrderIds = [],
        public array $failedOrders = [],
    ) {}
}
