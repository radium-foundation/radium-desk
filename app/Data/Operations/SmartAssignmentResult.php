<?php

namespace App\Data\Operations;

use App\Models\User;

readonly class SmartAssignmentResult
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public ?User $assignee,
        public string $outcome,
        public array $reasons = [],
        public array $context = [],
    ) {}

    public function isAssigned(): bool
    {
        return $this->assignee !== null;
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $context
     */
    public static function assigned(User $assignee, array $reasons, array $context): self
    {
        return new self(
            assignee: $assignee,
            outcome: 'assigned',
            reasons: $reasons,
            context: $context,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function unassigned(string $reason, array $context = []): self
    {
        return new self(
            assignee: null,
            outcome: 'unassigned',
            reasons: [],
            context: [
                'reason' => $reason,
                ...$context,
            ],
        );
    }
}
