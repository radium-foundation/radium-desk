<?php

namespace App\Data\Assignment;

use App\Models\Incident;
use App\Models\User;

final class SupportAssignmentResult
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Incident $incident,
        public readonly ?User $assignee,
        public readonly bool $assigned,
        public readonly array $reasons = [],
        public readonly array $context = [],
    ) {}

    public static function unchanged(Incident $incident): self
    {
        return new self(
            incident: $incident,
            assignee: $incident->assignee,
            assigned: false,
            reasons: ['already_assigned'],
        );
    }

    public static function unassigned(Incident $incident, string $reason, array $context = []): self
    {
        return new self(
            incident: $incident,
            assignee: null,
            assigned: false,
            reasons: [$reason],
            context: $context,
        );
    }

    public static function assigned(Incident $incident, User $assignee, array $reasons = [], array $context = []): self
    {
        return new self(
            incident: $incident->fresh(['assignee', 'order']),
            assignee: $assignee,
            assigned: true,
            reasons: $reasons,
            context: $context,
        );
    }
}
