<?php

namespace App\Infrastructure\Reconciliation;

use Carbon\CarbonInterface;

/**
 * Per-order reconciliation row for CSV export and operator review.
 */
readonly class ReconciliationOrderRow
{
    public function __construct(
        public string $orderId,
        public ?string $customer,
        public ?string $serial,
        public ?string $model,
        public ?string $syncStatus,
        public ?string $failureReason,
        public ?CarbonInterface $lastAttempt,
        public ?string $manualOverride,
    ) {}

    /**
     * @return list<string|null>
     */
    public function toCsvRow(): array
    {
        return [
            $this->orderId,
            $this->customer,
            $this->serial,
            $this->model,
            $this->syncStatus,
            $this->failureReason,
            $this->lastAttempt?->toIso8601String(),
            $this->manualOverride,
        ];
    }

    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'Order ID',
            'Customer',
            'Serial',
            'Model',
            'Sync Status',
            'Failure Reason',
            'Last Attempt',
            'Manual Override',
        ];
    }
}
