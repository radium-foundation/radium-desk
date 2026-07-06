<?php

namespace App\Services\Dashboard;

use App\Enums\ApprovalStatus;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RefundStatus;
use App\Models\ApprovalNumber;
use App\Models\Incident;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Operations\OperationsQueueClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardKpiAggregator
{
    /** @var array{resolved: int, closed: int}|null */
    private ?array $incidentStatusCounts = null;

    /** @var array{pending: int, approved: int, rejected: int}|null */
    private ?array $refundStatusCounts = null;

    /** @var array{open: int, total: int}|null */
    private ?array $approvalCounts = null;

    /**
     * @return array{
     *     open_incidents: int,
     *     total_active_cases: int,
     *     my_active_cases: int,
     *     waiting_for_admin: int,
     *     high_priority_cases: int,
     * }
     */
    public function activeIncidentKpis(Collection $activeIncidents, User $user): array
    {
        $userIncidents = $activeIncidents
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === $user->id);

        $activeCount = $activeIncidents->count();

        return [
            'open_incidents' => $activeCount,
            'total_active_cases' => $activeCount,
            'my_active_cases' => $userIncidents->count(),
            'waiting_for_admin' => $userIncidents
                ->filter(fn (Incident $incident): bool => $incident->isPendingAdmin())
                ->count(),
            'high_priority_cases' => $userIncidents
                ->filter(fn (Incident $incident): bool => (bool) $incident->high_priority)
                ->count(),
        ];
    }

    /**
     * @return array{
     *     my_active_work: int,
     *     my_attention: int,
     *     my_scheduled_today: int,
     *     my_waiting_follow_ups: int,
     *     my_completed_today: int,
     * }
     */
    public function supportAgentKpis(DashboardSnapshot $snapshot, User $user, ?Carbon $now = null): array
    {
        $now ??= now();
        $today = $now->copy()->startOfDay();
        $classifier = app(OperationsQueueClassifier::class);

        $myWork = $snapshot->myWorkIncidents($user);
        $attention = $snapshot->incidentsForFilter('my_attention', $user);
        $scheduledToday = $snapshot->incidentsForQueue(OperationQueue::Scheduled->value, $user)
            ->filter(function (Incident $incident) use ($today): bool {
                $appointments = $incident->relationLoaded('supportAppointments')
                    ? $incident->supportAppointments
                    : $incident->supportAppointments()->get();

                return $appointments->contains(
                    fn ($appointment): bool => $appointment->preferred_date !== null
                        && $appointment->preferred_date->isSameDay($today),
                );
            });
        $waitingFollowUps = $snapshot->incidentsForQueue(OperationQueue::WaitingCustomer->value, $user)
            ->filter(fn (Incident $incident): bool => $classifier->isWaitingFollowUpDue($incident));
        $completedToday = $snapshot->activeIncidents()
            ->filter(function (Incident $incident) use ($user, $today, $classifier): bool {
                if ($incident->assigned_to_user_id !== $user->id || ! $classifier->isCompleted($incident)) {
                    return false;
                }

                $completedAt = $incident->order?->completed_at;

                return $completedAt !== null && $completedAt->greaterThanOrEqualTo($today);
            });

        return [
            'my_active_work' => $myWork->count(),
            'my_attention' => $attention->count(),
            'my_scheduled_today' => $scheduledToday->count(),
            'my_waiting_follow_ups' => $waitingFollowUps->count(),
            'my_completed_today' => $completedToday->count(),
        ];
    }

    /**
     * @return array{resolved: int, closed: int}
     */
    public function incidentStatusCounts(): array
    {
        if ($this->incidentStatusCounts !== null) {
            return $this->incidentStatusCounts;
        }

        $counts = Incident::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->whereIn('status', [IncidentStatus::Resolved, IncidentStatus::Closed])
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return $this->incidentStatusCounts = [
            'resolved' => (int) ($counts[IncidentStatus::Resolved->value] ?? 0),
            'closed' => (int) ($counts[IncidentStatus::Closed->value] ?? 0),
        ];
    }

    /**
     * @return array{pending: int, approved: int, rejected: int}
     */
    public function refundStatusCounts(): array
    {
        if ($this->refundStatusCounts !== null) {
            return $this->refundStatusCounts;
        }

        $counts = RefundRequest::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return $this->refundStatusCounts = [
            'pending' => (int) ($counts[RefundStatus::Pending->value] ?? 0),
            'approved' => (int) ($counts[RefundStatus::Approved->value] ?? 0),
            'rejected' => (int) ($counts[RefundStatus::Rejected->value] ?? 0),
        ];
    }

    /**
     * @return array{open: int, total: int}
     */
    public function approvalCounts(): array
    {
        if ($this->approvalCounts !== null) {
            return $this->approvalCounts;
        }

        $openStatus = ApprovalStatus::Open->value;

        $row = ApprovalNumber::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open_count', [$openStatus])
            ->first();

        return $this->approvalCounts = [
            'open' => (int) ($row->open_count ?? 0),
            'total' => (int) ($row->total ?? 0),
        ];
    }
}
