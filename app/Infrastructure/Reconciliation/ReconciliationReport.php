<?php

namespace App\Infrastructure\Reconciliation;

use Carbon\CarbonInterface;

/**
 * Aggregate reconciliation metrics for all orders.
 */
readonly class ReconciliationReport
{
    public function __construct(
        public int $totalOrders,
        public int $ordersMissingSerial,
        public int $ordersMissingDeviceModel,
        public int $ordersMissingBoth,
        public int $ordersAwaitingSync,
        public int $ordersWithFailedSync,
        public int $ordersSuccessfullySynced,
        public int $ordersUsingManualSerial,
        public int $ordersUsingManualDeviceModel,
        public CarbonInterface $generatedAt,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'total_orders' => $this->totalOrders,
            'orders_missing_serial' => $this->ordersMissingSerial,
            'orders_missing_device_model' => $this->ordersMissingDeviceModel,
            'orders_missing_both' => $this->ordersMissingBoth,
            'orders_awaiting_sync' => $this->ordersAwaitingSync,
            'orders_with_failed_sync' => $this->ordersWithFailedSync,
            'orders_successfully_synced' => $this->ordersSuccessfullySynced,
            'orders_using_manual_serial' => $this->ordersUsingManualSerial,
            'orders_using_manual_device_model' => $this->ordersUsingManualDeviceModel,
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }
}
