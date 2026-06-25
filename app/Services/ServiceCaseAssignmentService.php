<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\User;
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
        private readonly SettingService $settingService,
    ) {}

    public function resolveAssignee(?Carbon $at = null): User
    {
        foreach ($this->assigneeCandidateUserIds($at) as $userId) {
            $assignee = $this->findValidAdminAssigneeById($userId);

            if ($assignee !== null) {
                return $assignee;
            }
        }

        throw ValidationException::withMessages([
            'assigned_to_user_id' => 'No valid admin assignee is available for service case assignment.',
        ]);
    }

    /**
     * @return list<int>
     */
    public function assigneeCandidateUserIds(?Carbon $at = null): array
    {
        $primary = $this->resolvePrimaryAssigneeUserId($at);
        $fallbacks = array_filter([
            $this->settingService->getInt('assignment.fallback_admin_1_user_id'),
            $this->settingService->getInt('assignment.fallback_admin_2_user_id'),
        ]);

        return array_values(array_unique(array_filter(array_merge([$primary], $fallbacks))));
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

    private function resolvePrimaryAssigneeUserId(?Carbon $at = null): int
    {
        $at = $this->normalizeTime($at ?? now());
        $time = $at->format('H:i');
        $start = $this->settingService->get('assignment.day_shift_start', '09:00');
        $end = $this->settingService->get('assignment.day_shift_end', '18:30');

        if ($time >= $start && $time <= $end) {
            return $this->settingService->getInt('assignment.day_shift_admin_user_id');
        }

        return $this->settingService->getInt('assignment.night_shift_admin_user_id');
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
        if (! $this->settingService->getBool('notifications.assignment_enabled', true)) {
            return;
        }

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

    private function findValidAdminAssigneeById(int $userId): ?User
    {
        if ($userId <= 0) {
            return null;
        }

        $assignee = User::query()->find($userId);

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
        return $at->copy()->timezone($this->settingService->get('assignment.timezone', config('app.timezone')));
    }
}
