<?php

namespace App\Services\Dashboard;

use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\User;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\ServiceCaseAssignmentService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Request-scoped lean classification index for dashboard COUNT paths.
 *
 * Loads only fields/relations required by OperationsQueueClassifier, performs
 * one classification pass per build, and precomputes queue + SLA + legacy
 * filter counts without repeated full-collection scans.
 */
class DashboardClassificationIndex
{
    private ?DashboardSnapshot $snapshot = null;

    public function getSnapshot(): DashboardSnapshot
    {
        return $this->snapshot ??= $this->buildSnapshot();
    }

    public function forget(): void
    {
        $this->snapshot = null;
    }

    public function hasSnapshot(): bool
    {
        return $this->snapshot !== null;
    }

    /**
     * Lean incident loader for snapshot cache encoding (no row-only relations).
     *
     * @return EloquentCollection<int, Incident>
     */
    public function loadLeanIncidents(): EloquentCollection
    {
        return Incident::query()
            ->select([
                'id',
                'order_record_id',
                'status',
                'assigned_to_user_id',
                'assignment_origin',
                'high_priority',
                'created_at',
                'automation_pending_until',
                'pending_smart_assignment',
            ])
            ->with([
                'order' => static function ($query): void {
                    $query->select([
                        'id',
                        'order_id',
                        'serial_number',
                        'product_name',
                        'device_model',
                        'transaction_id',
                        'status',
                    ]);
                },
                'order.refundRequests' => static function ($query): void {
                    $query->select([
                        'id',
                        'order_id',
                        'incident_id',
                        'status',
                        'approved_refund_method',
                        'executed_at',
                        'closed_at',
                        'reviewed_at',
                        'updated_at',
                        'reference_no',
                        'amount',
                        'refund_amount',
                    ]);
                },
                'assignee.roles',
                'refundRequests' => static function ($query): void {
                    $query->select([
                        'id',
                        'order_id',
                        'incident_id',
                        'status',
                        'approved_refund_method',
                        'executed_at',
                        'closed_at',
                        'reviewed_at',
                        'updated_at',
                        'reference_no',
                        'amount',
                        'refund_amount',
                    ]);
                },
                'activeWaitingState',
                'activeBusinessHold',
                'supportAppointments' => static function ($query): void {
                    $query->select([
                        'id',
                        'incident_id',
                        'status',
                        'preferred_date',
                    ]);
                },
            ])
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->get();
    }

    /**
     * @return CachedActiveIncidentSnapshot
     */
    public function buildCachedSnapshot(EloquentCollection $incidents): CachedActiveIncidentSnapshot
    {
        $classifier = app(OperationsQueueClassifier::class)->rememberClassifications();
        $built = $this->buildFromIncidents($incidents, $classifier);

        return new CachedActiveIncidentSnapshot(
            incidents: $incidents,
            queueCounts: $built->queueCounts(),
            slaCounts: $built->slaCounts(),
        );
    }

    private function buildSnapshot(): DashboardSnapshot
    {
        $classifier = app(OperationsQueueClassifier::class)->rememberClassifications();
        $incidents = $this->loadLeanIncidents();

        return $this->buildFromIncidents($incidents, $classifier);
    }

    private function buildFromIncidents(
        EloquentCollection $incidents,
        OperationsQueueClassifier $classifier,
    ): DashboardSnapshot {
        $now = now();
        $queues = OperationQueue::cases();
        $assignmentService = app(ServiceCaseAssignmentService::class);

        /** @var array<string, list<Incident>> $rawBuckets */
        $rawBuckets = [];

        foreach ($queues as $queue) {
            $rawBuckets[$queue->value] = [];
        }

        $slaService = ['overdue_cases' => 0, 'warning_cases' => 0];
        $slaHardware = ['overdue_cases' => 0, 'warning_cases' => 0];

        foreach ($incidents as $incident) {
            $queue = $classifier->classify($incident);
            $rawBuckets[$queue->value][] = $incident;

            if ($queue !== OperationQueue::ActionRequired
                && $classifier->isReadyForReferenceEntry($incident)) {
                $rawBuckets[OperationQueue::ActionRequired->value][] = $incident;
            }

            $this->accumulateSlaCounts($incident, $classifier, $now, $slaService, $slaHardware);
        }

        $slaCounts = [
            'overdue_cases' => $slaService['overdue_cases'] + $slaHardware['overdue_cases'],
            'warning_cases' => $slaService['warning_cases'] + $slaHardware['warning_cases'],
            'service_overdue_cases' => $slaService['overdue_cases'],
            'service_warning_cases' => $slaService['warning_cases'],
            'hardware_overdue_cases' => $slaHardware['overdue_cases'],
            'hardware_warning_cases' => $slaHardware['warning_cases'],
        ];

        $queueIncidentsAll = $this->materializeQueueIncidents(
            $incidents,
            $rawBuckets,
            null,
            $assignmentService,
            $classifier,
        );

        $queueCountsAll = $this->queueCountsFromMaterialized($queues, $queueIncidentsAll, null);

        $snapshot = new DashboardSnapshot(
            $incidents,
            $classifier,
            $queueCountsAll,
            $slaCounts,
            $queueIncidentsAll,
            $rawBuckets,
        );

        $filterCountsAll = $queueCountsAll;
        foreach ($snapshot->computeLegacyFilterCountsSinglePass(null) as $legacyFilter => $count) {
            $filterCountsAll[$legacyFilter] = $count;
        }
        $snapshot->rememberFilterCountsForScope(null, $filterCountsAll);

        $this->snapshot = $snapshot;

        return $snapshot;
    }

    /**
     * @param  EloquentCollection<int, Incident>  $allIncidents
     * @param  array<string, list<Incident>>  $rawBuckets
     * @return array<string, Collection<int, Incident>>
     */
    private function materializeQueueIncidents(
        EloquentCollection $allIncidents,
        array $rawBuckets,
        ?User $scopeUser,
        ServiceCaseAssignmentService $assignmentService,
        OperationsQueueClassifier $classifier,
    ): array {
        $materialized = [];

        foreach (OperationQueue::cases() as $queue) {
            if ($queue === OperationQueue::MyWork) {
                $incidents = $scopeUser === null
                    ? collect()
                    : $allIncidents->filter(
                        fn (Incident $incident): bool => $classifier->matchesMyWork($incident, $scopeUser),
                    );
            } else {
                $incidents = collect($rawBuckets[$queue->value]);

                if ($scopeUser !== null && $queue === OperationQueue::WaitingCustomer) {
                    $incidents = $incidents->filter(
                        fn (Incident $incident): bool => $classifier->isAssignedWaitingCustomer($incident, $scopeUser),
                    );
                }

                if ($scopeUser !== null && $queue === OperationQueue::Completed) {
                    $incidents = $incidents->filter(
                        fn (Incident $incident): bool => $incident->assigned_to_user_id === $scopeUser->id,
                    );
                }
            }

            if ($queue === OperationQueue::ActionRequired && $scopeUser === null) {
                $assignmentService->prefetchAdminReadyVisibility($incidents);
                $incidents = $incidents->filter(
                    fn (Incident $incident): bool => $assignmentService->isVisibleInAdminReadyQueue($incident),
                );
            }

            $materialized[$this->queueCacheKey($queue->value, $scopeUser)] = $incidents->values();
        }

        return $materialized;
    }

    /**
     * @param  list<OperationQueue>  $queues
     * @param  array<string, Collection<int, Incident>>  $materialized
     * @return array<string, int>
     */
    private function queueCountsFromMaterialized(
        array $queues,
        array $materialized,
        ?User $scopeUser,
    ): array {
        $counts = [];

        foreach ($queues as $queue) {
            $key = $this->queueCacheKey($queue->value, $scopeUser);
            $counts[$queue->value] = ($materialized[$key] ?? collect())->count();
        }

        return $counts;
    }

    private function accumulateSlaCounts(
        Incident $incident,
        OperationsQueueClassifier $classifier,
        Carbon $now,
        array &$serviceCounts,
        array &$hardwareCounts,
    ): void {
        if (! $incident->isPendingAdmin()) {
            return;
        }

        $sla = $incident->slaStatus($now);

        if ($sla !== ServiceCaseSlaStatus::Overdue && $sla !== ServiceCaseSlaStatus::Warning) {
            return;
        }

        $target = $classifier->isHardware($incident) ? $hardwareCounts : $serviceCounts;

        if ($sla === ServiceCaseSlaStatus::Overdue) {
            $target['overdue_cases']++;
        } else {
            $target['warning_cases']++;
        }
    }

    private function queueCacheKey(string $queue, ?User $scopeUser): string
    {
        return $queue.':'.($scopeUser?->id ?? 'all');
    }
}
