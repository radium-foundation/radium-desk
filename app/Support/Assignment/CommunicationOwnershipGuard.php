<?php

namespace App\Support\Assignment;

use App\Enums\Assignment\AssignmentTrigger;
use App\Models\Incident;

/**
 * Communication events must never change existing case ownership.
 */
class CommunicationOwnershipGuard
{
    public function preservesOwnership(Incident $incident): bool
    {
        return $incident->assigned_to_user_id !== null;
    }

    public function shouldSkipAssignment(Incident $incident, AssignmentTrigger $trigger): bool
    {
        if (! $this->isCommunicationTrigger($trigger)) {
            return false;
        }

        return $this->preservesOwnership($incident);
    }

    public function isCommunicationTrigger(AssignmentTrigger $trigger): bool
    {
        return in_array($trigger, [
            AssignmentTrigger::CommunicationIntake,
            AssignmentTrigger::EmailTriage,
        ], true);
    }
}
