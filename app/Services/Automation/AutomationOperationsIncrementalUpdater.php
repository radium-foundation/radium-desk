<?php

namespace App\Services\Automation;

use App\Data\AutomationOperationsDashboardData;
use App\Enums\AutomationSnapshotSlice;
use App\Services\AutomationOperationsSnapshotBuilder;
use App\Services\Cashfree\CashfreeWebhookReliabilityMetrics;

/**
 * Applies dirty automation-ops snapshot slices without a full rebuild when possible.
 *
 * Phase 8 infrastructure:
 * - Cashfree KPIs → merge from CashfreeWebhookReliabilityMetrics (120s sub-cache)
 * - Recent events → rebuild audit-tail only
 * - Health / Validation / Repair → full builder pass (specialized later)
 */
class AutomationOperationsIncrementalUpdater
{
    /** If dirty flags sit unapplied this long, force a full rebuild (missed-event safety). */
    public const STALE_DIRTY_SECONDS = 120;

    public function __construct(
        private readonly AutomationOperationsSnapshotBuilder $builder,
        private readonly AutomationOperationsSnapshotInvalidator $invalidator,
        private readonly CashfreeWebhookReliabilityMetrics $cashfreeReliabilityMetrics,
    ) {}

    public function shouldFullRebuild(bool $forceReconcile): bool
    {
        if ($forceReconcile) {
            return true;
        }

        if ($this->invalidator->requiresFullRebuild()) {
            return true;
        }

        $age = $this->invalidator->dirtyAgeSeconds();

        return $age !== null && $age >= self::STALE_DIRTY_SECONDS;
    }

    /**
     * Refresh Cashfree KPI keys inside healthCounts (cheap; uses 120s sub-cache).
     */
    public function mergeCashfreeKpis(AutomationOperationsDashboardData $snapshot): AutomationOperationsDashboardData
    {
        $healthCounts = $snapshot->healthCounts;
        $cashfree = $this->cashfreeReliabilityMetrics->dashboardCounts();

        foreach ($cashfree as $key => $value) {
            $healthCounts[$key] = $value;
        }

        return new AutomationOperationsDashboardData(
            healthCounts: $healthCounts,
            waitingForCustomerSerialQueue: $snapshot->waitingForCustomerSerialQueue,
            duplicateSerialConflicts: $snapshot->duplicateSerialConflicts,
            radiumBoxNotFoundQueue: $snapshot->radiumBoxNotFoundQueue,
            recentAutomationEvents: $snapshot->recentAutomationEvents,
            repairStatistics: $snapshot->repairStatistics,
            validationByProduct: $snapshot->validationByProduct,
            validationByValidatorRule: $snapshot->validationByValidatorRule,
            validationByCategory: $snapshot->validationByCategory,
        );
    }

    public function replaceRecentEvents(AutomationOperationsDashboardData $snapshot): AutomationOperationsDashboardData
    {
        return new AutomationOperationsDashboardData(
            healthCounts: $snapshot->healthCounts,
            waitingForCustomerSerialQueue: $snapshot->waitingForCustomerSerialQueue,
            duplicateSerialConflicts: $snapshot->duplicateSerialConflicts,
            radiumBoxNotFoundQueue: $snapshot->radiumBoxNotFoundQueue,
            recentAutomationEvents: $this->builder->recentAutomationEvents(),
            repairStatistics: $snapshot->repairStatistics,
            validationByProduct: $snapshot->validationByProduct,
            validationByValidatorRule: $snapshot->validationByValidatorRule,
            validationByCategory: $snapshot->validationByCategory,
        );
    }

    /**
     * @return list<AutomationSnapshotSlice>
     */
    public function pendingLightSlices(): array
    {
        $pending = [];

        foreach ($this->invalidator->dirtySlices() as $slice) {
            if ($slice === AutomationSnapshotSlice::RecentEvents
                || $slice === AutomationSnapshotSlice::Cashfree
            ) {
                $pending[] = $slice;
            }
        }

        return $pending;
    }
}
