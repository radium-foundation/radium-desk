<?php

namespace App\Services;

use App\Data\AutomationOperationsDashboardData;
use App\Enums\AutomationSnapshotSlice;
use App\Enums\IncidentStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\OutboxEvent;
use App\Services\Automation\AutomationOperationsIncrementalUpdater;
use App\Services\Automation\AutomationOperationsSnapshotInvalidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutomationOperationsSnapshotService
{
    public const CACHE_KEY = 'automation.operations.snapshot';

    public const META_CACHE_KEY = 'automation.operations.snapshot.meta';

    /**
     * Long enough to survive between 15-minute reconciles; event-driven dirty
     * + light ticks refresh content without relying on short TTL expiry.
     */
    public const TTL_SECONDS = 900;

    public function __construct(
        private readonly AutomationOperationsSnapshotBuilder $builder,
        private readonly AutomationOperationsSnapshotInvalidator $invalidator,
        private readonly AutomationOperationsIncrementalUpdater $incrementalUpdater,
    ) {}

    public function get(): AutomationOperationsDashboardData
    {
        $cached = Cache::get(self::CACHE_KEY);
        $meta = Cache::get(self::META_CACHE_KEY);

        if (is_array($cached) && is_array($meta) && is_array($meta['incident_stubs'] ?? null)) {
            if ($this->invalidator->isDirty()) {
                return $this->refreshDetailed()['snapshot'];
            }

            return $this->applyTimeDependentFields(
                AutomationOperationsDashboardData::fromCacheArray($cached),
                $meta['incident_stubs'],
            );
        }

        return $this->refresh();
    }

    /**
     * @return array{
     *     snapshot: AutomationOperationsDashboardData,
     *     rebuilt: bool,
     *     mode: string,
     *     fingerprint: string,
     *     dirty_slices: list<string>
     * }
     */
    public function refreshDetailed(bool $forceReconcile = false): array
    {
        $dirtyBefore = array_map(
            static fn (AutomationSnapshotSlice $slice): string => $slice->value,
            $this->invalidator->dirtySlices(),
        );

        $cached = Cache::get(self::CACHE_KEY);
        $meta = Cache::get(self::META_CACHE_KEY);
        $hasCache = is_array($cached) && is_array($meta) && is_array($meta['incident_stubs'] ?? null);

        if (! $hasCache || $this->incrementalUpdater->shouldFullRebuild($forceReconcile)) {
            return $this->fullRebuild(
                mode: $forceReconcile ? 'reconcile' : 'full-rebuild',
                dirtyBefore: $dirtyBefore,
            );
        }

        $snapshot = AutomationOperationsDashboardData::fromCacheArray($cached);
        $applied = [];

        // Cashfree KPIs always soft-refresh on light ticks (120s sub-cache; cheap).
        $snapshot = $this->incrementalUpdater->mergeCashfreeKpis($snapshot);
        $applied[] = AutomationSnapshotSlice::Cashfree->value;

        if (in_array(AutomationSnapshotSlice::RecentEvents, $this->invalidator->dirtySlices(), true)
            || in_array(AutomationSnapshotSlice::All, $this->invalidator->dirtySlices(), true)
        ) {
            $snapshot = $this->incrementalUpdater->replaceRecentEvents($snapshot);
            $applied[] = AutomationSnapshotSlice::RecentEvents->value;
            $this->invalidator->clearSlices([AutomationSnapshotSlice::RecentEvents]);
        }

        $snapshot = $this->applyTimeDependentFields($snapshot, $meta['incident_stubs']);

        $fingerprint = $this->contentFingerprint();

        Cache::put(self::CACHE_KEY, $snapshot->toCacheArray(), self::TTL_SECONDS);
        Cache::put(self::META_CACHE_KEY, [
            ...$meta,
            'fingerprint' => $fingerprint,
            'last_light_at' => now()->toIso8601String(),
            'last_mode' => 'incremental',
            'last_slices' => $applied,
        ], self::TTL_SECONDS);

        $this->invalidator->clearSlices([AutomationSnapshotSlice::Cashfree]);

        return [
            'snapshot' => $snapshot,
            'rebuilt' => false,
            'mode' => 'incremental',
            'fingerprint' => $fingerprint,
            'dirty_slices' => $dirtyBefore,
        ];
    }

    public function refresh(): AutomationOperationsDashboardData
    {
        return $this->refreshDetailed()['snapshot'];
    }

    public function reconcile(): AutomationOperationsDashboardData
    {
        return $this->refreshDetailed(forceReconcile: true)['snapshot'];
    }

    /**
     * Cheap content fingerprint — used for reconcile diagnostics / missed-event detection.
     * No longer the primary gate for skipping rebuilds (event-driven dirty flags are).
     */
    public function contentFingerprint(): string
    {
        $activeStatuses = array_map(
            static fn (IncidentStatus $status): string => $status->value,
            IncidentStatus::operationallyActive(),
        );

        $incidentAgg = Incident::query()
            ->whereIn('status', $activeStatuses)
            ->whereNotNull('order_id')
            ->selectRaw('COUNT(*) as aggregate_count, MAX(updated_at) as max_updated_at, MAX(id) as max_id')
            ->first();

        $orderAgg = DB::table('orders')
            ->join('incidents', 'incidents.order_id', '=', 'orders.id')
            ->whereIn('incidents.status', $activeStatuses)
            ->whereNotNull('incidents.order_id')
            ->whereNull('incidents.deleted_at')
            ->whereNull('orders.deleted_at')
            ->selectRaw('COUNT(DISTINCT orders.id) as aggregate_count, MAX(orders.updated_at) as max_updated_at')
            ->first();

        $automationEvents = [
            ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            'service_case.automation_pending',
            ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED,
            ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED,
            ServiceCaseAutomationMonitorService::EVENT_WAITING_MANUAL_CORRECTION,
            'service_case.assigned',
            'service_case.reassigned',
        ];

        $maxAutomationAuditId = AuditLog::query()
            ->whereIn('event', $automationEvents)
            ->max('id');

        $repairAudit = AuditLog::query()
            ->where('event', OrderIdentityRepairService::AUDIT_EVENT)
            ->selectRaw('COUNT(*) as aggregate_count, MAX(id) as max_id')
            ->first();

        $failedWebhookAgg = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
            ->selectRaw('COUNT(*) as aggregate_count, MAX(id) as max_id')
            ->first();

        $outboxPending = OutboxEvent::query()
            ->where('status', OutboxEventStatus::Pending)
            ->count();
        $outboxFailed = OutboxEvent::query()
            ->where('status', OutboxEventStatus::Failed)
            ->count();

        $ordersCreated = (int) Cache::get('cashfree:webhook:reliability:orders_created', 0);

        return hash('xxh128', implode('|', [
            (string) ($incidentAgg?->aggregate_count ?? 0),
            (string) ($incidentAgg?->max_updated_at ?? ''),
            (string) ($incidentAgg?->max_id ?? 0),
            (string) ($orderAgg?->aggregate_count ?? 0),
            (string) ($orderAgg?->max_updated_at ?? ''),
            (string) ($maxAutomationAuditId ?? 0),
            (string) ($repairAudit?->aggregate_count ?? 0),
            (string) ($repairAudit?->max_id ?? 0),
            (string) ($failedWebhookAgg?->aggregate_count ?? 0),
            (string) ($failedWebhookAgg?->max_id ?? 0),
            (string) $outboxPending,
            (string) $outboxFailed,
            (string) $ordersCreated,
        ]));
    }

    /**
     * @param  list<string>  $dirtyBefore
     * @return array{
     *     snapshot: AutomationOperationsDashboardData,
     *     rebuilt: bool,
     *     mode: string,
     *     fingerprint: string,
     *     dirty_slices: list<string>
     * }
     */
    private function fullRebuild(string $mode, array $dirtyBefore): array
    {
        $previousFingerprint = null;
        $previousMeta = Cache::get(self::META_CACHE_KEY);

        if (is_array($previousMeta)) {
            $previousFingerprint = $previousMeta['fingerprint'] ?? null;
        }

        $built = $this->builder->buildDetailed();
        $snapshot = $built['data'];
        $fingerprint = $this->contentFingerprint();

        if (
            $mode === 'reconcile'
            && $dirtyBefore === []
            && is_string($previousFingerprint)
            && $previousFingerprint !== ''
            && $previousFingerprint !== $fingerprint
        ) {
            Log::warning('automation.operations.snapshot.missed_invalidation', [
                'previous_fingerprint' => $previousFingerprint,
                'fingerprint' => $fingerprint,
            ]);
        }

        Cache::put(self::CACHE_KEY, $snapshot->toCacheArray(), self::TTL_SECONDS);
        Cache::put(self::META_CACHE_KEY, [
            'fingerprint' => $fingerprint,
            'incident_stubs' => $built['incident_stubs'],
            'built_at' => now()->toIso8601String(),
            'last_mode' => $mode,
            'last_slices' => [AutomationSnapshotSlice::All->value],
        ], self::TTL_SECONDS);

        $this->invalidator->clear();

        return [
            'snapshot' => $snapshot,
            'rebuilt' => true,
            'mode' => $mode,
            'fingerprint' => $fingerprint,
            'dirty_slices' => $dirtyBefore,
        ];
    }

    /**
     * @param  list<array{
     *     created_at: ?string,
     *     status: string,
     *     assigned_to_user_id: ?int,
     *     automation_pending_until: ?string
     * }>  $stubs
     */
    private function applyTimeDependentFields(
        AutomationOperationsDashboardData $snapshot,
        array $stubs,
    ): AutomationOperationsDashboardData {
        $waitingOverFive = 0;
        $waitingOverFifteen = 0;
        $graceExpired = 0;
        $unassigned = 0;
        $thresholdFive = now()->subMinutes(5);
        $thresholdFifteen = now()->subMinutes(15);

        foreach ($stubs as $stub) {
            $status = ServiceCaseAutomationStatus::tryFrom((string) ($stub['status'] ?? ''))
                ?? ServiceCaseAutomationStatus::Completed;
            $createdAt = isset($stub['created_at']) && is_string($stub['created_at']) && $stub['created_at'] !== ''
                ? Carbon::parse($stub['created_at'])
                : null;
            $automationPendingUntil = isset($stub['automation_pending_until'])
                && is_string($stub['automation_pending_until'])
                && $stub['automation_pending_until'] !== ''
                ? Carbon::parse($stub['automation_pending_until'])
                : null;

            if (($stub['assigned_to_user_id'] ?? null) === null) {
                $unassigned++;
            }

            if ($automationPendingUntil !== null && $automationPendingUntil->isPast()) {
                $graceExpired++;
            }

            if ($createdAt !== null
                && $status !== ServiceCaseAutomationStatus::Completed
                && $status !== ServiceCaseAutomationStatus::AssignedToAdmin
            ) {
                if ($createdAt->lte($thresholdFive)) {
                    $waitingOverFive++;
                }

                if ($createdAt->lte($thresholdFifteen)) {
                    $waitingOverFifteen++;
                }
            }
        }

        $healthCounts = $snapshot->healthCounts;
        $healthCounts['waiting_over_5_min'] = $waitingOverFive;
        $healthCounts['waiting_over_15_min'] = $waitingOverFifteen;
        $healthCounts['unassigned'] = $unassigned;
        $healthCounts['grace_expired'] = $graceExpired;

        $waitingQueue = array_map(static function (array $row): array {
            $createdAtIso = $row['created_at_iso'] ?? null;

            if (is_string($createdAtIso) && $createdAtIso !== '') {
                $row['age'] = Carbon::parse($createdAtIso)->diffForHumans();
            }

            return $row;
        }, $snapshot->waitingForCustomerSerialQueue);

        return new AutomationOperationsDashboardData(
            healthCounts: $healthCounts,
            waitingForCustomerSerialQueue: $waitingQueue,
            duplicateSerialConflicts: $snapshot->duplicateSerialConflicts,
            radiumBoxNotFoundQueue: $snapshot->radiumBoxNotFoundQueue,
            recentAutomationEvents: $snapshot->recentAutomationEvents,
            repairStatistics: $snapshot->repairStatistics,
            validationByProduct: $snapshot->validationByProduct,
            validationByValidatorRule: $snapshot->validationByValidatorRule,
            validationByCategory: $snapshot->validationByCategory,
        );
    }
}
