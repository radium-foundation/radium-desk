<?php

namespace App\Services\Dashboard;

use App\Models\Incident;
use App\Services\Operations\OperationsQueueClassifier;

/**
 * Request-scoped store for the active-incident dashboard snapshot.
 *
 * Prevents duplicate incident loads during the same HTTP request.
 */
class DashboardSnapshotStore
{
    private ?DashboardSnapshot $snapshot = null;

    public function get(): DashboardSnapshot
    {
        return $this->snapshot ??= $this->loadFresh();
    }

    public function forget(): void
    {
        $this->snapshot = null;
    }

    private function loadFresh(): DashboardSnapshot
    {
        return new DashboardSnapshot(
            Incident::query()
                ->with([
                    'order.deviceModel',
                    'order.transactionAssigner',
                    'order.legacyImporter',
                    'creator',
                    'assignee.roles',
                    'activeWaitingState',
                    'activeBusinessHold',
                    'supportAppointments',
                ])
                ->whereIn('status', \App\Enums\IncidentStatus::operationallyActive())
                ->get(),
            app(OperationsQueueClassifier::class),
        );
    }
}
