<?php

namespace App\Support\Assignment;

use App\Enums\Assignment\AssignmentQueue;
use App\Enums\OperationQueue;
use App\Models\Incident;
use App\Services\Operations\OperationsQueueClassifier;

class AssignmentQueueResolver
{
    public function __construct(
        private readonly OperationsQueueClassifier $queueClassifier,
    ) {}

    public function resolve(Incident $incident): AssignmentQueue
    {
        $incident = $incident->loadMissing([
            'order',
            'assignee',
            'activeWaitingState',
            'supportAppointments',
        ]);

        $operationQueue = $this->queueClassifier->classify($incident);

        return match ($operationQueue) {
            OperationQueue::ActionRequired => AssignmentQueue::Ready,
            OperationQueue::WaitingCustomer => AssignmentQueue::WaitingCustomer,
            OperationQueue::Completed => AssignmentQueue::Completed,
            default => AssignmentQueue::Support,
        };
    }
}
