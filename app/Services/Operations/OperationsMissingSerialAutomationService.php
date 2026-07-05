<?php

namespace App\Services\Operations;

use App\Data\Operations\MissingSerialAutomationQualitySummary;
use App\Enums\MissingSerialAutomationStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class OperationsMissingSerialAutomationService
{
    public function qualitySummary(): MissingSerialAutomationQualitySummary
    {
        $needSerial = $this->needSerialQuery()->count();

        $autoRequested = Order::query()
            ->whereNotNull('missing_serial_first_requested_at')
            ->count();

        $customerReplied = Order::query()
            ->whereNotNull('missing_serial_first_requested_at')
            ->whereNotNull('serial_entered_at')
            ->whereColumn('serial_entered_at', '>=', 'missing_serial_first_requested_at')
            ->count();

        $coordinatorFollowUp = Order::query()
            ->where('missing_serial_automation_status', MissingSerialAutomationStatus::Escalated->value)
            ->count();

        return new MissingSerialAutomationQualitySummary(
            needSerial: $needSerial,
            autoRequested: $autoRequested,
            customerReplied: $customerReplied,
            coordinatorFollowUp: $coordinatorFollowUp,
        );
    }

    /**
     * @return Builder<Order>
     */
    private function needSerialQuery(): Builder
    {
        return Order::query()
            ->cashfreeVerified()
            ->whereSerialMissing()
            ->where(function (Builder $query): void {
                $query->where('radiumbox_sync_attempts', '>', 0)
                    ->orWhere('radiumbox_sync_status', '!=', RadiumBoxEnrichmentSyncStatus::NotSynced->value)
                    ->orWhereNotNull('radiumbox_last_sync_at');
            });
    }
}
