<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\User;
use App\Notifications\HighPriorityServiceCaseNotification;
use App\Notifications\ServiceCaseAssignedNotification;
use App\Notifications\ServiceCaseReassignedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCaseAssignmentService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function resolveAssigneeEmail(?Carbon $at = null): string
    {
        $at = $this->normalizeTime($at ?? now());
        $dayShift = config('service_case_assignment.day_shift');
        $time = $at->format('H:i');

        if ($time >= $dayShift['start'] && $time <= $dayShift['end']) {
            return (string) $dayShift['assignee_email'];
        }

        return (string) config('service_case_assignment.after_hours.assignee_email');
    }

    public function resolveAssignee(?Carbon $at = null): User
    {
        foreach ($this->assigneeCandidateEmails($at) as $email) {
            $assignee = $this->findValidAdminAssignee($email);

            if ($assignee !== null) {
                return $assignee;
            }
        }

        throw ValidationException::withMessages([
            'assigned_to_user_id' => 'No valid admin assignee is available for service case assignment.',
        ]);
    }

    /**
     * @return list<string>
     */
    public function assigneeCandidateEmails(?Carbon $at = null): array
    {
        $primary = $this->resolveAssigneeEmail($at);
        $fallbacks = config('service_case_assignment.fallback_admins', []);

        return array_values(array_unique(array_merge([$primary], $fallbacks)));
    }

    public function assignOnCreate(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        return $this->applyAssignment(
            incident: $incident,
            assignee: $this->resolveAssignee($at),
            actor: $actor,
            event: 'service_case.assigned',
        );
    }

    public function reassign(Incident $incident, User $assignee, User $actor): Incident
    {
        $this->ensureAdminAssignee($assignee);

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.reassigned',
        );
    }

    /**
     * @return list<User>
     */
    public function reassignableAdmins(): array
    {
        return User::query()
            ->where('is_active', true)
            ->role([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ])
            ->orderBy('name')
            ->get()
            ->all();
    }

    private function applyAssignment(
        Incident $incident,
        User $assignee,
        User $actor,
        string $event,
    ): Incident {
        return DB::transaction(function () use ($incident, $assignee, $actor, $event): Incident {
            $oldValues = [
                'assigned_to_user_id' => $incident->assigned_to_user_id,
            ];

            $incident->update([
                'assigned_to_user_id' => $assignee->id,
                'updated_by' => $actor->id,
            ]);

            $freshIncident = $incident->fresh(['assignee']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: $event,
                auditable: $freshIncident,
                oldValues: $oldValues,
                newValues: [
                    'assigned_to_user_id' => $freshIncident->assigned_to_user_id,
                ],
            );

            $this->sendAssignmentNotifications(
                incident: $freshIncident,
                assignee: $assignee,
                actor: $actor,
                event: $event,
            );

            return $freshIncident;
        });
    }

    private function sendAssignmentNotifications(
        Incident $incident,
        User $assignee,
        User $actor,
        string $event,
    ): void {
        if (! $assignee->is_active || $assignee->trashed()) {
            return;
        }

        if ($event === 'service_case.assigned') {
            $assignee->notify(new ServiceCaseAssignedNotification($incident, $actor));
        }

        if ($event === 'service_case.reassigned') {
            $assignee->notify(new ServiceCaseReassignedNotification($incident, $actor));
        }
    }

    private function ensureAdminAssignee(User $assignee): void
    {
        if ($assignee->trashed() || ! $assignee->is_active || ! $assignee->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ])) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'The selected user must be an admin.',
            ]);
        }
    }

    private function findValidAdminAssignee(string $email): ?User
    {
        $assignee = User::query()
            ->where('email', $email)
            ->first();

        if ($assignee === null || $assignee->trashed() || ! $assignee->is_active) {
            return null;
        }

        if (! $assignee->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ])) {
            return null;
        }

        return $assignee;
    }

    private function normalizeTime(Carbon $at): Carbon
    {
        return $at->copy()->timezone(config('service_case_assignment.timezone'));
    }
}
