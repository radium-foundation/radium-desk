<?php

namespace App\Services\Dashboard;

use App\Services\Operations\OperationsQueueClassifier;

/**
 * Request-scoped store for the active-incident dashboard snapshot.
 *
 * Prevents duplicate incident loads during the same HTTP request and
 * reuses a short-TTL cross-request cache via OperatorDashboardCache.
 *
 * COUNT paths load lean incidents via DashboardClassificationIndex.
 * ROW paths batch-load row-only relations in DashboardService::mapServiceCaseRows.
 */
class DashboardSnapshotStore
{
    private ?DashboardSnapshot $snapshot = null;

    private bool $requestScopedOnly = false;

    public function __construct(
        private readonly DashboardClassificationIndex $classificationIndex,
    ) {}

    public function get(): DashboardSnapshot
    {
        return $this->snapshot ??= $this->loadFresh();
    }

    public function forget(): void
    {
        $this->snapshot = null;
        $this->requestScopedOnly = false;
        $this->classificationIndex->forget();
        app(OperationsQueueClassifier::class)->forgetClassifications();
        app(OperatorDashboardCache::class)->forgetSnapshot();
    }

    /**
     * Rebuild snapshot for broadcast/KPI paths without repopulating cross-request cache.
     */
    public function useRequestScopedSnapshotOnly(): void
    {
        $this->requestScopedOnly = true;
    }

    private function loadFresh(): DashboardSnapshot
    {
        $cache = app(OperatorDashboardCache::class);
        $index = $this->classificationIndex;

        if (! $cache->snapshotCacheEnabled() || $this->requestScopedOnly) {
            return $index->getSnapshot();
        }

        $classifier = app(OperationsQueueClassifier::class)->rememberClassifications();

        $cached = $cache->rememberCachedSnapshot(
            fn () => $index->loadLeanIncidents(),
        );

        if ($cached->hasPrecomputedMetrics() && $index->hasSnapshot()) {
            return $index->getSnapshot();
        }

        return new DashboardSnapshot(
            $cached->incidents,
            $classifier,
            $cached->queueCounts,
            $cached->slaCounts,
        );
    }
}
