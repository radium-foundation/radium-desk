<?php

namespace App\Services\Assignment;

use App\Data\Assignment\UserAssignmentWorkload;
use App\Enums\OperationQueue;
use App\Models\Incident;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\SmartAssignmentService;

class SupportAssignmentWorkloadService
{
    public function __construct(
        private readonly SmartAssignmentService $smartAssignmentService,
        private readonly OperationsQueueClassifier $queueClassifier,
    ) {}

    public function forUser(User $user, ?DashboardSnapshot $snapshot = null): UserAssignmentWorkload
    {
        $snapshot ??= DashboardSnapshot::load();
        $metrics = $this->smartAssignmentService->workloadMetrics($user, $snapshot);

        $assigned = $snapshot->activeIncidents()
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === $user->id);

        $waitingCases = $assigned
            ->filter(fn (Incident $incident): bool => $this->queueClassifier->classify($incident) === OperationQueue::WaitingCustomer)
            ->count();

        $activeAssignedCases = $metrics['open_cases'];
        $appointmentCases = $metrics['scheduled_total'];
        $totalWorkload = $activeAssignedCases + $waitingCases + $appointmentCases;

        return new UserAssignmentWorkload(
            activeAssignedCases: $activeAssignedCases,
            waitingCases: $waitingCases,
            appointmentCases: $appointmentCases,
            totalWorkload: $totalWorkload,
        );
    }

    /**
     * @param  list<User>  $users
     * @return array<int, UserAssignmentWorkload>
     */
    public function forUsers(array $users, ?DashboardSnapshot $snapshot = null): array
    {
        $snapshot ??= DashboardSnapshot::load();
        $workloads = [];

        foreach ($users as $user) {
            $workloads[$user->id] = $this->forUser($user, $snapshot);
        }

        return $workloads;
    }
}
