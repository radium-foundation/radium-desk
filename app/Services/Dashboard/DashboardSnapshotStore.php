<?php

namespace App\Services\Dashboard;

use App\Models\Incident;
use App\Services\Operations\OperationsQueueClassifier;

/**
 * Request-scoped store for the active-incident dashboard snapshot.
 *
 * Prevents duplicate incident loads during the same HTTP request and
 * reuses a short-TTL cross-request cache via OperatorDashboardCache.
 *
 * Shared cache holds a serializable array projection only; this store
 * rehydrates Eloquent models for DashboardSnapshot consumers.
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
        app(OperationsQueueClassifier::class)->forgetClassifications();
        app(OperatorDashboardCache::class)->forgetSnapshot();
    }

    private function loadFresh(): DashboardSnapshot
    {
        $classifier = app(OperationsQueueClassifier::class)->rememberClassifications();
        $cache = app(OperatorDashboardCache::class);

        $incidents = $cache->rememberActiveIncidents(
            fn () => Incident::query()
                ->with([
                    'order.deviceModel',
                    'order.transactionAssigner',
                    'order.legacyImporter',
                    'order.refundRequests',
                    'refundRequests',
                    'creator',
                    'assignee.roles',
                    'activeWaitingState',
                    'activeBusinessHold',
                    'supportAppointments',
                ])
                ->whereIn('status', \App\Enums\IncidentStatus::operationallyActive())
                ->get(),
        );

        return new DashboardSnapshot($incidents, $classifier);
    }
}
