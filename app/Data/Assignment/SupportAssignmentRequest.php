<?php

namespace App\Data\Assignment;

use App\Enums\Assignment\AssignmentTrigger;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Carbon;

final class SupportAssignmentRequest
{
    /**
     * @param  array<string, mixed>  $auditContext
     */
    public function __construct(
        public readonly Incident $incident,
        public readonly User $actor,
        public readonly AssignmentTrigger $trigger,
        public readonly ?Carbon $at = null,
        public readonly string $auditEvent = 'service_case.assigned',
        public readonly array $auditContext = [],
        public readonly ?string $unassignedReason = null,
    ) {}

    public function at(): Carbon
    {
        return $this->at ?? now();
    }
}
