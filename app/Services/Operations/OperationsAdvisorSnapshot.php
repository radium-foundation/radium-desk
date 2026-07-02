<?php

namespace App\Services\Operations;

use App\Data\Operations\OperationsDashboardData;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use Illuminate\Support\Collection;

class OperationsAdvisorSnapshot
{
    /** @var Collection<int, Incident>|null */
    private ?Collection $pendingAdminIncidents = null;

    /** @var Collection<int, Incident>|null */
    private ?Collection $activeIncidents = null;

    /** @var array{overdue_cases: int, warning_cases: int}|null */
    private ?array $slaCounts = null;

    /** @var Collection<int, Collection<int, Incident>>|null */
    private ?Collection $engineerWorkloads = null;

    /** @var Collection<int, IncidentWaitingState>|null */
    private ?Collection $longWaitingStates = null;

    public function __construct(
        public readonly OperationsDashboardData $dashboard,
    ) {}

    /**
     * @return Collection<int, Incident>
     */
    public function pendingAdminIncidents(): Collection
    {
        if ($this->pendingAdminIncidents !== null) {
            return $this->pendingAdminIncidents;
        }

        return $this->pendingAdminIncidents = Incident::query()
            ->with(['order', 'assignee', 'activeWaitingState'])
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->whereHas('order', function ($orderQuery): void {
                $orderQuery->where(function ($pendingQuery): void {
                    $pendingQuery->whereNull('transaction_id')
                        ->orWhere('transaction_id', '');
                });
            })
            ->get();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function activeIncidents(): Collection
    {
        if ($this->activeIncidents !== null) {
            return $this->activeIncidents;
        }

        return $this->activeIncidents = Incident::query()
            ->with(['order', 'assignee'])
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->get();
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function slaCounts(): array
    {
        if ($this->slaCounts !== null) {
            return $this->slaCounts;
        }

        $now = now();
        $pending = $this->pendingAdminIncidents();

        return $this->slaCounts = [
            'overdue_cases' => $pending
                ->filter(fn (Incident $incident): bool => $incident->slaStatus($now)->value === 'overdue')
                ->count(),
            'warning_cases' => $pending
                ->filter(fn (Incident $incident): bool => $incident->slaStatus($now)->value === 'warning')
                ->count(),
        ];
    }

    /**
     * @return Collection<int, Collection<int, Incident>>
     */
    public function engineerWorkloads(): Collection
    {
        if ($this->engineerWorkloads !== null) {
            return $this->engineerWorkloads;
        }

        return $this->engineerWorkloads = $this->activeIncidents()
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id !== null)
            ->groupBy('assigned_to_user_id');
    }

    /**
     * @return Collection<int, IncidentWaitingState>
     */
    public function longWaitingStates(int $minimumDays = 3): Collection
    {
        if ($this->longWaitingStates !== null) {
            return $this->longWaitingStates;
        }

        $threshold = now()->subDays($minimumDays);

        return $this->longWaitingStates = IncidentWaitingState::query()
            ->with(['incident.order', 'incident.assignee'])
            ->whereNull('cleared_at')
            ->where('started_at', '<=', $threshold)
            ->get();
    }
}
