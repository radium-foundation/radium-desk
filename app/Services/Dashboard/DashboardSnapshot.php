<?php

namespace App\Services\Dashboard;

use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardSnapshot
{
    /** @var Collection<int, Incident> */
    private Collection $activeIncidents;

    /** @var Collection<int, Incident>|null */
    private ?Collection $pendingAdminIncidents = null;

    /** @var Collection<int, Incident>|null */
    private ?Collection $completedIncidents = null;

    /** @var Collection<int, Incident>|null */
    private ?Collection $unassignedIncidents = null;

    /** @var array{overdue_cases: int, warning_cases: int}|null */
    private ?array $slaCounts = null;

    /**
     * @param  Collection<int, Incident>  $activeIncidents
     */
    public function __construct(Collection $activeIncidents)
    {
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
                ])
                ->whereIn('status', IncidentStatus::operationallyActive())
                ->get(),
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
     * @return Collection<int, Incident>
     */
    public function pendingAdmin(): Collection
    {
        if ($this->pendingAdminIncidents !== null) {
            return $this->pendingAdminIncidents;
        }

        return $this->pendingAdminIncidents = $this->activeIncidents
            ->filter(fn (Incident $incident): bool => $incident->isPendingAdmin())
            ->values();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function completed(): Collection
    {
        if ($this->completedIncidents !== null) {
            return $this->completedIncidents;
        }

        return $this->completedIncidents = $this->activeIncidents
            ->filter(fn (Incident $incident): bool => $incident->order !== null && $incident->order->isTransactionLocked())
            ->values();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function unassigned(): Collection
    {
        if ($this->unassignedIncidents !== null) {
            return $this->unassignedIncidents;
        }

        return $this->unassignedIncidents = $this->activeIncidents
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === null)
            ->values();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function overdue(?Carbon $now = null): Collection
    {
        $now ??= now();

        return $this->pendingAdmin()
            ->filter(fn (Incident $incident): bool => $incident->slaStatus($now) === ServiceCaseSlaStatus::Overdue)
            ->values();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function warning(?Carbon $now = null): Collection
    {
        $now ??= now();

        return $this->pendingAdmin()
            ->filter(fn (Incident $incident): bool => $incident->slaStatus($now) === ServiceCaseSlaStatus::Warning)
            ->values();
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function slaCounts(?Carbon $now = null): array
    {
        if ($this->slaCounts !== null) {
            return $this->slaCounts;
        }

        $now ??= now();

        return $this->slaCounts = [
            'overdue_cases' => $this->overdue($now)->count(),
            'warning_cases' => $this->warning($now)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function filterCounts(?User $assignedTo = null, ?User $user = null): array
    {
        return collect(['all', 'pending_admin', 'completed', 'high_priority', 'needs_attention', 'my_cases', 'pending_support'])
            ->mapWithKeys(fn (string $key): array => [
                $key => $this->incidentsForFilter(
                    $key,
                    match ($key) {
                        'my_cases' => $user,
                        'pending_support' => null,
                        default => $assignedTo,
                    },
                )->count(),
            ])
            ->all();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function incidentsForFilter(string $filter, ?User $assignmentScope): Collection
    {
        if ($filter === 'pending_support') {
            return $this->applyFilter($filter, $this->unassigned());
        }

        $incidents = $this->applyAssignmentScope($this->activeIncidents, $assignmentScope);

        return $this->applyFilter($filter, $incidents);
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    private function applyAssignmentScope(Collection $incidents, ?User $assignmentScope): Collection
    {
        if ($assignmentScope === null) {
            return $incidents;
        }

        return $incidents
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === $assignmentScope->id)
            ->values();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    private function applyFilter(string $filter, Collection $incidents): Collection
    {
        return match ($filter) {
            'pending_admin', 'overdue', 'warning' => $incidents
                ->filter(fn (Incident $incident): bool => $incident->isPendingAdmin())
                ->values(),
            'completed' => $incidents
                ->filter(fn (Incident $incident): bool => $incident->order !== null && $incident->order->isTransactionLocked())
                ->values(),
            'high_priority' => $incidents
                ->filter(fn (Incident $incident): bool => (bool) $incident->high_priority)
                ->values(),
            'needs_attention' => $incidents
                ->filter(fn (Incident $incident): bool => $this->orderSerialMissing($incident->order))
                ->values(),
            'pending_support' => $incidents,
            default => $incidents,
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
