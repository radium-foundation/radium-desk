<?php

namespace App\Data\Assignment;

use App\Enums\Assignment\AssignmentTrigger;
use App\Enums\Assignment\EmailAssignmentClassification;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Carbon;

final class AssignmentRequest
{
    public function __construct(
        public readonly Incident $incident,
        public readonly User $actor,
        public readonly AssignmentTrigger $trigger,
        public readonly ?Carbon $at = null,
        public readonly ?EmailAssignmentClassification $emailClassification = null,
        public readonly bool $preserveExistingOwnership = false,
        public readonly ?string $fallbackAuditEvent = null,
    ) {}

    public static function make(
        Incident $incident,
        User $actor,
        AssignmentTrigger $trigger,
        ?Carbon $at = null,
        ?EmailAssignmentClassification $emailClassification = null,
        bool $preserveExistingOwnership = false,
        ?string $fallbackAuditEvent = null,
    ): self {
        return new self(
            incident: $incident,
            actor: $actor,
            trigger: $trigger,
            at: $at,
            emailClassification: $emailClassification,
            preserveExistingOwnership: $preserveExistingOwnership,
            fallbackAuditEvent: $fallbackAuditEvent,
        );
    }
}
