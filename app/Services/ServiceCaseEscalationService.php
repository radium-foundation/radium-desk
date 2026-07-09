<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;

class ServiceCaseEscalationService
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
    ) {}

    public function canEscalate(Incident $incident, User $actor): bool
    {
        if ($incident->status === IncidentStatus::Closed) {
            return false;
        }

        if (! $actor->can('update', $incident)) {
            return false;
        }

        return $this->resolveLevel1Target() !== null;
    }

    public function escalate(Incident $incident, User $actor, string $reason): Incident
    {
        $target = $this->resolveLevel1Target();

        if ($target === null) {
            throw ValidationException::withMessages([
                'action_type' => 'No escalation specialist is available to receive this case.',
            ]);
        }

        return $this->assignmentService->escalate(
            incident: $incident,
            assignee: $target,
            actor: $actor,
            reason: $reason,
        );
    }

    public function resolveLevel1Target(): ?User
    {
        return $this->resolveEscalationSpecialistByEmail(
            (string) config('service_case_assignment.escalation.level_1_email'),
        );
    }

    /**
     * Future level-2 escalation target (escalation specialist → operations admin).
     */
    public function resolveLevel2Target(): ?User
    {
        $email = (string) config('service_case_assignment.escalation.level_2_email');

        if ($email === '') {
            return null;
        }

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if ($user !== null && $user->hasAnyRole([
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_ADMIN,
        ])) {
            return $user;
        }

        return $this->resolveEscalationSpecialistByEmail($email);
    }

    private function resolveEscalationSpecialistByEmail(string $email): ?User
    {
        if ($email === '') {
            return null;
        }

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if ($user === null || $user->trashed() || ! $user->hasRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST)) {
            return null;
        }

        return $user;
    }
}
