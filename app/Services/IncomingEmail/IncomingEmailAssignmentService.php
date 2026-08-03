<?php

namespace App\Services\IncomingEmail;

use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Notifications\HighPriorityServiceCaseNotification;
use App\Notifications\NewEmailReceivedNotification;
use App\Enums\AssignmentOrigin;
use App\Enums\TeamAvailabilityStatus;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\WorkforceAuthorityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Email Phase 1.1 — communication follows customer ownership.
 *
 * Existing Incident/Service Case assignee always wins (notify only).
 * Unassigned matched cases use Communication Intake primary → fallback.
 * Never round-robins. Never reassigns.
 */
class IncomingEmailAssignmentService
{
    public const SETTING_PRIMARY = 'assignment.communication_intake_primary_user_id';

    public const SETTING_FALLBACK = 'assignment.communication_intake_fallback_user_id';

    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly WorkforceAuthorityService $workforceAuthority,
        private readonly PresenceEngineService $presenceEngine,
        private readonly SettingService $settingService,
    ) {}

    /**
     * Route ownership for a newly linked inbound email and notify the owner.
     */
    public function routeLinkedEmail(
        Incident $incident,
        IncomingEmailMessage $message,
        User $actor,
        ?Carbon $at = null,
    ): Incident {
        $at ??= now();
        $incident = $incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            $this->notifyOwnerOfNewEmail($incident, $message);
            $this->notifyHighPriorityIfNeeded($incident, $actor);

            return $incident;
        }

        $incident = $this->assignCommunicationIntake($incident, $actor, $at);
        $this->notifyOwnerOfNewEmail($incident->fresh(['assignee']), $message);
        $this->notifyHighPriorityIfNeeded($incident, $actor);

        return $incident->fresh(['assignee']);
    }

    /**
     * @deprecated Use routeLinkedEmail(); retained for callers that only need unassigned intake.
     */
    public function assignIfUnassigned(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        $at ??= now();
        $incident = $incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        return $this->assignCommunicationIntake($incident, $actor, $at);
    }

    public function notifyOwnerOfNewEmail(Incident $incident, IncomingEmailMessage $message): void
    {
        $incident = $incident->fresh(['assignee']);
        $assignee = $incident->assignee;

        if ($assignee === null || ! $assignee->is_active || $assignee->trashed()) {
            return;
        }

        $assignee->notify(new NewEmailReceivedNotification($incident, $message));
    }

    private function assignCommunicationIntake(Incident $incident, User $actor, Carbon $at): Incident
    {
        $primary = $this->resolveConfiguredUser(self::SETTING_PRIMARY);
        $fallback = $this->resolveConfiguredUser(self::SETTING_FALLBACK);

        $assignee = null;
        $method = 'communication_intake';
        $reason = null;

        if ($primary instanceof User && $this->isAvailableForCommunicationIntake($primary, $at)) {
            $assignee = $primary;
            $method = 'communication_intake_primary';
            $reason = 'primary_available';
        } elseif ($fallback instanceof User && $this->isAvailableForCommunicationIntake($fallback, $at)) {
            $assignee = $fallback;
            $method = 'communication_intake_fallback';
            $reason = $primary instanceof User ? 'primary_unavailable' : 'primary_unconfigured';
        } elseif ($fallback instanceof User && $fallback->is_active && ! $fallback->trashed()) {
            // Last resort: still assign fallback even if soft-unavailable so the case is not ownerless.
            $assignee = $fallback;
            $method = 'communication_intake_fallback_forced';
            $reason = 'fallback_forced';
        } elseif ($primary instanceof User && $primary->is_active && ! $primary->trashed()) {
            $assignee = $primary;
            $method = 'communication_intake_primary_forced';
            $reason = 'primary_forced';
        }

        if ($assignee === null) {
            Log::warning('incoming_email.communication_intake_unresolved', [
                'incident_id' => $incident->id,
                'primary_user_id' => $primary?->id,
                'fallback_user_id' => $fallback?->id,
            ]);

            return $incident;
        }

        return $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: [
                'assignment_method' => $method,
                'assignment_reason' => $reason,
                'intake_channel' => 'email',
                'primary_user_id' => $primary?->id,
                'fallback_user_id' => $fallback?->id,
            ],
            event: 'service_case.assigned',
            assignmentOrigin: AssignmentOrigin::Auto,
        );
    }

    public function isAvailableForCommunicationIntake(User $user, ?Carbon $at = null): bool
    {
        if (! $user->is_active || $user->trashed()) {
            return false;
        }

        if ($this->workforceAuthority->isOnApprovedLeave($user, $at)) {
            return false;
        }

        // Holiday, weekly off, outside configured working hours.
        if (! $this->workforceAuthority->calendarAllows($user, $at)) {
            return false;
        }

        // Explicit Offline status.
        if ($user->availability_status === TeamAvailabilityStatus::Offline) {
            return false;
        }

        // Clocked-in but Away counts as offline for intake.
        if ($this->presenceEngine->openSessionFor($user) !== null
            && $this->workforceAuthority->isPresent($user, $at) === false) {
            return false;
        }

        return true;
    }

    private function resolveConfiguredUser(string $settingKey): ?User
    {
        $userId = $this->settingService->getInt($settingKey);

        if ($userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);

        return $user instanceof User ? $user : null;
    }

    private function notifyHighPriorityIfNeeded(Incident $incident, User $actor): void
    {
        $incident = $incident->fresh(['assignee']);

        if (! $incident->high_priority
            || $incident->assignee === null
            || ! $incident->assignee->is_active
            || $incident->assignee->trashed()
            || ! $this->settingService->getBool('notifications.high_priority_enabled', true)) {
            return;
        }

        $incident->assignee->notify(new HighPriorityServiceCaseNotification($incident, $actor));
    }
}
