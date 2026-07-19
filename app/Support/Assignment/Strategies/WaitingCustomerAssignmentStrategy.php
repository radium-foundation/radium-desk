<?php

namespace App\Support\Assignment\Strategies;

use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentQueue;
use App\Models\Incident;
use App\Support\Assignment\Contracts\AssignmentStrategy;

class WaitingCustomerAssignmentStrategy implements AssignmentStrategy
{
    public function queue(): AssignmentQueue
    {
        return AssignmentQueue::WaitingCustomer;
    }

    public function assign(AssignmentRequest $request): Incident
    {
        return $request->incident->fresh(['assignee', 'order']);
    }
}
