<?php

namespace App\Enums;

/**
 * Dirty slices for the automation operations dashboard snapshot.
 *
 * Phase 8 infrastructure: write hubs mark slices; the incremental updater
 * refreshes only what is needed (or falls back to a full rebuild).
 */
enum AutomationSnapshotSlice: string
{
    /** Active-incident health counts + waiting/unassigned/grace time fields. */
    case Health = 'health';

    /** Validation failure queues + product/rule/category aggregates. */
    case Validation = 'validation';

    /** Cashfree reliability KPIs merged into healthCounts. */
    case Cashfree = 'cashfree';

    /** Recent automation audit-tail feed. */
    case RecentEvents = 'recent_events';

    /** Order identity repair statistics. */
    case Repair = 'repair';

    /** Force a full rebuild of every slice. */
    case All = 'all';

    /**
     * Slices that require a full builder pass (incident scan + validation).
     *
     * @return list<self>
     */
    public static function fullRebuildTriggers(): array
    {
        return [
            self::Health,
            self::Validation,
            self::Repair,
            self::All,
        ];
    }
}
