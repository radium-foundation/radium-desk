<?php

namespace App\Services\Operations;

use App\Enums\TeamAvailabilityChangeSource;
use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TeamAvailabilityService
{
    public const MANUAL_OFFLINE_BLOCKED_MESSAGE = 'You are currently on duty. Logout to end your shift or set Busy if temporarily unavailable.';

    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
        private readonly OperationsRoleService $roleService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function statusFor(User $user): TeamAvailabilityStatus
    {
        $stored = $user->getRawOriginal('availability_status');

        if (! is_string($stored) || $stored === '') {
            $stored = $user->availability_status;
        }

        if ($stored instanceof TeamAvailabilityStatus) {
            return $stored;
        }

        if (is_string($stored) && $stored !== '') {
            if ($stored === 'on_leave') {
                return TeamAvailabilityStatus::Offline;
            }

            return TeamAvailabilityStatus::tryFrom($stored) ?? TeamAvailabilityStatus::Offline;
        }

        return TeamAvailabilityStatus::Offline;
    }

    public function isOnLeave(User $user, ?Carbon $date = null): bool
    {
        return $this->workCalendarService->hasApprovedLeave($user, $date);
    }

    public function restrictsOfflineSelfService(User $user): bool
    {
        return $this->roleService->isAttendanceTracked($user)
            && WorkSession::query()
                ->where('user_id', $user->id)
                ->whereNull('logout_at')
                ->exists();
    }

    public function updateStatus(
        User $user,
        TeamAvailabilityStatus $status,
        ?User $actor = null,
        ?TeamAvailabilityChangeSource $source = null,
    ): User {
        if ($source === TeamAvailabilityChangeSource::Manual
            && $status === TeamAvailabilityStatus::Offline
            && $this->restrictsOfflineSelfService($user)) {
            throw ValidationException::withMessages([
                'availability_status' => self::MANUAL_OFFLINE_BLOCKED_MESSAGE,
            ]);
        }

        $oldStatus = $this->statusFor($user);

        if ($oldStatus === $status) {
            return $user->fresh();
        }

        $user->fill([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);
        $user->save();

        $resolvedSource = $source ?? TeamAvailabilityChangeSource::Manual;

        $this->auditLogService->log(
            userId: $actor?->id ?? $user->id,
            event: 'user.availability_changed',
            auditable: $user->fresh(),
            oldValues: [
                'status' => $oldStatus->value,
            ],
            newValues: [
                'status' => $status->value,
                'source' => $resolvedSource->value,
            ],
        );

        return $user->fresh();
    }

    public function syncFromSessionStart(User $user, ?Carbon $at = null): User
    {
        $at ??= now();

        if ($this->workCalendarService->hasApprovedLeave($user, $at)
            || ! $this->workCalendarService->isEligibleForAssignment($user, $at)) {
            return $this->updateStatus(
                user: $user,
                status: TeamAvailabilityStatus::Offline,
                actor: $user,
                source: TeamAvailabilityChangeSource::Login,
            );
        }

        $current = $this->statusFor($user);

        if ($current === TeamAvailabilityStatus::Offline) {
            return $this->updateStatus(
                user: $user,
                status: TeamAvailabilityStatus::Available,
                actor: $user,
                source: TeamAvailabilityChangeSource::Login,
            );
        }

        return $user->fresh();
    }

    public function syncFromSessionEnd(
        User $user,
        TeamAvailabilityChangeSource $source = TeamAvailabilityChangeSource::Logout,
    ): User {
        return $this->updateStatus(
            user: $user,
            status: TeamAvailabilityStatus::Offline,
            actor: $user,
            source: $source,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(User $user): array
    {
        $status = $this->statusFor($user);

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'updated_at' => $user->availability_updated_at?->toIso8601String(),
            'on_leave' => $this->isOnLeave($user),
            'restricts_offline_self_service' => $this->restrictsOfflineSelfService($user),
        ];
    }
}
