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
        public int $ordersProcessed,
        public int $ordersRepaired,
        public int $ordersSkipped,
        public int $ordersAlreadyValid,
        public int $ordersFailed,
        public int $rateLimited,
        public int $duplicateSerials,
        public int $notFound,
        public int $validationFailed,
        public int $unexpectedFailures,
        public int $assignmentsEscalated,
        public int $assignmentsToAgent,
        public int $assignmentsUnchanged,
        public float $elapsedSeconds,
        public array $repairedOrderIds = [],
        public array $failedOrders = [],
    ) {}
}
