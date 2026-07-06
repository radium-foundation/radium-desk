<?php

namespace App\Services\Dashboard;

use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\OperationsQueueClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardSnapshot
{
    /** @var Collection<int, Incident> */
    private Collection $activeIncidents;

    /** @var array{overdue_cases: int, warning_cases: int, service_overdue_cases: int, service_warning_cases: int, hardware_overdue_cases: int, hardware_warning_cases: int}|null */
    private ?array $slaCounts = null;

    /** @var array<string, Collection<int, Incident>>|null */
    private ?array $queueIncidents = null;

    /** @var array<string, array{open_cases: int, waiting_cases: int}> */
    private array $operationalKpiCounts = [];

    public function __construct(
        Collection $activeIncidents,
        private readonly OperationsQueueClassifier $queueClassifier,
    ) {
        $this->activeIncidents = $activeIncidents;
    }

    public static function load(): self
    {
        return new self(
            Incident::query()
                ->with([
                    'order.deviceModel',
                    'order.transactionAssigner',
                    'creator',
                    'assignee.roles',
                    'activeWaitingState',
                    'supportAppointments',
                ])
                ->whereIn('status', \App\Enums\IncidentStatus::operationallyActive())
                ->get(),
            app(OperationsQueueClassifier::class),
        );
    }

    /**
     * @return Collection<int, Incident>
     */
    public function activeIncidents(): Collection
    {
        return $this->activeIncidents;
    }

    /**
     * @return array{
     *     overdue_cases: int,
     *     warning_cases: int,
     *     service_overdue_cases: int,
     *     service_warning_cases: int,
     *     hardware_overdue_cases: int,
     *     hardware_warning_cases: int
     * }
     */
    public function slaCounts(?Carbon $now = null): array
    {
        if ($this->slaCounts !== null) {
            return $this->slaCounts;
        }

        $now ??= now();
        $service = $this->countSlaStatuses(
            $this->activeIncidents->filter(fn (Incident $incident): bool => ! $this->queueClassifier->isHardware($incident)),
            $now,
        );
        $hardware = $this->countSlaStatuses(
            $this->activeIncidents->filter(fn (Incident $incident): bool => $this->queueClassifier->isHardware($incident)),
            $now,
        );

        return $this->slaCounts = [
            'overdue_cases' => $service['overdue_cases'] + $hardware['overdue_cases'],
            'warning_cases' => $service['warning_cases'] + $hardware['warning_cases'],
            'service_overdue_cases' => $service['overdue_cases'],
            'service_warning_cases' => $service['warning_cases'],
            'hardware_overdue_cases' => $hardware['overdue_cases'],
            'hardware_warning_cases' => $hardware['warning_cases'],
        ];
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function serviceSlaCounts(?Carbon $now = null): array
    {
        $counts = $this->slaCounts($now);

        return [
            'overdue_cases' => $counts['service_overdue_cases'],
            'warning_cases' => $counts['service_warning_cases'],
        ];
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function hardwareSlaCounts(?Carbon $now = null): array
    {
        $counts = $this->slaCounts($now);

        return [
            'overdue_cases' => $counts['hardware_overdue_cases'],
            'warning_cases' => $counts['hardware_warning_cases'],
        ];
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return array{overdue_cases: int, warning_cases: int}
     */
    private function countSlaStatuses(Collection $incidents, Carbon $now): array
    {
        return [
            'overdue_cases' => $incidents
                ->filter(fn (Incident $incident): bool => $incident->isPendingAdmin()
                    && $incident->slaStatus($now) === ServiceCaseSlaStatus::Overdue)
                ->count(),
            'warning_cases' => $incidents
                ->filter(fn (Incident $incident): bool => $incident->isPendingAdmin()
                    && $incident->slaStatus($now) === ServiceCaseSlaStatus::Warning)
                ->count(),
        ];
    }

    /**
     * @return Collection<int, Incident>
     */
    public function overdue(?Carbon $now = null): Collection
    {
        $now ??= now();

        return $this->activeIncidents
            ->filter(fn (Incident $incident): bool => $incident->isPendingAdmin()
                && $incident->slaStatus($now) === ServiceCaseSlaStatus::Overdue)
            ->values();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function warning(?Carbon $now = null): Collection
    {
        $now ??= now();

        return $this->activeIncidents
            ->filter(fn (Incident $incident): bool => $incident->isPendingAdmin()
                && $incident->slaStatus($now) === ServiceCaseSlaStatus::Warning)
            ->values();
    }

    /**
     * @return array{open_cases: int, waiting_cases: int}
     */
    public function operationalKpiCounts(?User $scopeUser = null): array
    {
        $cacheKey = (string) ($scopeUser?->id ?? 'all');

        if (isset($this->operationalKpiCounts[$cacheKey])) {
            return $this->operationalKpiCounts[$cacheKey];
        }

        return $this->operationalKpiCounts[$cacheKey] = [
            'open_cases' => $this->openCount($scopeUser),
            'waiting_cases' => $this->waitingCount(),
        ];
    }

    public function openCount(?User $scopeUser = null): int
    {
        if ($scopeUser !== null) {
            return $this->openIncidents($scopeUser)->count();
        }

        $counts = $this->queueCounts();

        return ($counts[OperationQueue::ActionRequired->value] ?? 0)
            + ($counts[OperationQueue::Scheduled->value] ?? 0)
            + ($counts[OperationQueue::Attention->value] ?? 0);
    }

    public function myWorkCount(User $scopeUser): int
    {
        return $this->incidentsForQueue(OperationQueue::MyWork->value, $scopeUser)->count();
    }

    public function waitingCount(): int
    {
        return $this->queueCounts()[OperationQueue::WaitingCustomer->value] ?? 0;
    }

    /**
     * @return Collection<int, Incident>
     */
    public function openIncidents(?User $scopeUser = null): Collection
    {
        return $this->activeIncidents
            ->filter(function (Incident $incident) use ($scopeUser): bool {
                $queue = $this->queueClassifier->classify($incident);

                if (in_array($queue, [
                    OperationQueue::WaitingCustomer,
                    OperationQueue::Completed,
                    OperationQueue::Hardware,
                ], true)) {
                    return false;
                }

                if ($scopeUser !== null && $incident->assigned_to_user_id !== $scopeUser->id) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function myWorkIncidents(User $scopeUser): Collection
    {
        return $this->incidentsForQueue(OperationQueue::MyWork->value, $scopeUser);
    }

    /**
     * @return array<string, int>
     */
    public function queueCounts(?User $scopeUser = null): array
    {
        return collect(OperationQueue::cases())
            ->mapWithKeys(fn (OperationQueue $queue): array => [
                $queue->value => $this->incidentsForQueue($queue->value, $scopeUser)->count(),
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function filterCounts(?User $assignedTo = null, ?User $user = null): array
    {
        $counts = $this->queueCounts($assignedTo);

        foreach ($this->legacyFilterKeys() as $legacyFilter) {
            $counts[$legacyFilter] = $this->incidentsForFilter($legacyFilter, $assignedTo)->count();
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function legacyFilterKeys(): array
    {
        return [
            'pending_admin',
            'needs_attention',
            'high_priority',
            'overdue',
            'warning',
            'all',
            'completed',
            'my_cases',
            'pending_support',
        ];
    }

    /**
     * @return Collection<int, Incident>
     */
    public function incidentsForQueue(string $queue, ?User $scopeUser = null): Collection
    {
        $cached = $this->queueIncidents[$this->queueCacheKey($queue, $scopeUser)] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        return $this->queueIncidents[$this->queueCacheKey($queue, $scopeUser)] = $this->activeIncidents
            ->filter(fn (Incident $incident): bool => $this->queueClassifier->matchesQueue($incident, $queue, $scopeUser))
            ->values();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function incidentsForFilter(string $filter, ?User $assignmentScope): Collection
    {
        $queue = $this->mapLegacyFilterToQueue($filter);

        if ($filter === 'my_cases') {
            return $this->incidentsForQueue(OperationQueue::MyWork->value, $assignmentScope);
        }

        if ($filter === 'pending_admin') {
            return $this->activeIncidents
                ->filter(function (Incident $incident) use ($assignmentScope): bool {
                    if (! $incident->isPendingAdmin()) {
                        return false;
                    }

                    if ($this->queueClassifier->isHardware($incident)) {
                        return false;
                    }

                    if ($assignmentScope !== null && $incident->assigned_to_user_id !== $assignmentScope->id) {
                        return false;
                    }

                    return true;
                })
                ->values();
        }

        if ($filter === 'needs_attention') {
            return $this->activeIncidents
                ->filter(function (Incident $incident) use ($assignmentScope): bool {
                    if (! $incident->isActive() || $incident->status === IncidentStatus::Closed) {
                        return false;
                    }

                    if ($assignmentScope !== null && $incident->assigned_to_user_id !== $assignmentScope->id) {
                        return false;
                    }

                    return $this->orderSerialMissing($incident->order);
                })
                ->values();
        }

        if (in_array($filter, ['overdue', 'warning'], true)) {
            return $this->incidentsForQueue(OperationQueue::Attention->value, $assignmentScope)
                ->filter(fn (Incident $incident): bool => $incident->slaStatus(now()) === match ($filter) {
                    'overdue' => ServiceCaseSlaStatus::Overdue,
                    default => ServiceCaseSlaStatus::Warning,
                })
                ->values();
        }

        if ($filter === 'high_priority') {
            return $this->incidentsForQueue(OperationQueue::Attention->value, $assignmentScope)
                ->filter(fn (Incident $incident): bool => (bool) $incident->high_priority)
                ->values();
        }

        if ($filter === 'all') {
            return $this->activeIncidents
                ->filter(fn (Incident $incident): bool => ! $this->queueClassifier->isCompleted($incident))
                ->values();
        }

        if ($assignmentScope !== null && $queue !== OperationQueue::MyWork->value) {
            return $this->incidentsForQueue($queue, $assignmentScope)
                ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === $assignmentScope->id)
                ->values();
        }

        return $this->incidentsForQueue($queue, $this->scopeUserForQueue($queue, $assignmentScope));
    }

    private function scopeUserForQueue(string $queue, ?User $assignmentScope): ?User
    {
        if ($queue === OperationQueue::MyWork->value) {
            return $assignmentScope;
        }

        return null;
    }

    private function queueCacheKey(string $queue, ?User $scopeUser): string
    {
        return $queue.':'.($scopeUser?->id ?? 'all');
    }

    private function mapLegacyFilterToQueue(string $filter): string
    {
        return match ($filter) {
            'completed' => OperationQueue::Completed->value,
            'pending_support', 'needs_attention', 'overdue', 'warning', 'high_priority' => OperationQueue::Attention->value,
            'pending_admin' => OperationQueue::ActionRequired->value,
            'my_cases' => OperationQueue::MyWork->value,
            default => OperationQueue::ActionRequired->value,
        };
    }

    private function orderSerialMissing(?Order $order): bool
    {
        if ($order === null) {
            return false;
        }

        $serial = $order->serial_number;

        if ($serial === null || $serial === '') {
            return true;
        }

        return trim($serial) === '';
    }
}
