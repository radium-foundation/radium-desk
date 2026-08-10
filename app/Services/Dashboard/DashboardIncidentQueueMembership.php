<?php

namespace App\Services\Dashboard;

use App\Enums\OperationQueue;
use App\Models\Incident;
use App\Models\User;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\ServiceCaseAssignmentService;

/**
 * Single-incident queue membership — mirrors DashboardSnapshot::incidentsForQueue
 * without loading the full active-incident snapshot.
 */
final class DashboardIncidentQueueMembership
{
    public function __construct(
        private readonly OperationsQueueClassifier $queueClassifier,
        private readonly ServiceCaseAssignmentService $assignmentService,
    ) {}

    public function isVisibleInQueue(Incident $incident, string $queue, ?User $scopeUser = null): bool
    {
        if ($queue === OperationQueue::MyWork->value) {
            return $this->queueClassifier->matchesMyWork($incident, $scopeUser);
        }

        if ($this->queueClassifier->matchesQueue($incident, $queue, $scopeUser)) {
            return $this->passesScopeFilters($incident, $queue, $scopeUser);
        }

        if ($queue === OperationQueue::ActionRequired->value
            && $this->queueClassifier->isReadyForReferenceEntry($incident)) {
            return $this->passesScopeFilters($incident, $queue, $scopeUser);
        }

        return false;
    }

    private function passesScopeFilters(Incident $incident, string $queue, ?User $scopeUser): bool
    {
        if ($scopeUser !== null && $queue === OperationQueue::WaitingCustomer->value) {
            return $this->queueClassifier->isAssignedWaitingCustomer($incident, $scopeUser);
        }

        if ($scopeUser !== null && $queue === OperationQueue::Completed->value) {
            return $incident->assigned_to_user_id === $scopeUser->id;
        }

        if ($queue === OperationQueue::ActionRequired->value && $scopeUser === null) {
            return $this->assignmentService->isVisibleInAdminReadyQueue($incident);
        }

        return true;
    }
}
