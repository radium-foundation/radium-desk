<?php

namespace App\Services\IncomingEmail;

use App\Models\Incident;
use App\Models\User;
use App\Notifications\HighPriorityServiceCaseNotification;
use App\Services\AuditLogService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Illuminate\Support\Carbon;

/**
 * Mirrors BonvoiceMissedCallRecoveryService::assignForRecovery for inbound email.
 */
class IncomingEmailAssignmentService
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly SettingService $settingService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function assignIfUnassigned(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        $at ??= now();
        $incident = $incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        $incident = $this->assignForIncomingEmail($incident, $actor, $at);
        $this->notifyHighPriorityIfNeeded($incident, $actor);

        return $incident;
    }

    private function assignForIncomingEmail(Incident $incident, User $actor, Carbon $at): Incident
    {
        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        if ($incident->order?->isInquiryOrder()) {
            if ($this->isWithinSupportHours($at)) {
                return $this->assignmentService->assignViaRoundRobinAfterGracePeriod($incident, $actor);
            }

            return $this->assignmentService->assignInquiryViaRoundRobin($incident, $actor, $at);
        }

        if ($this->isWithinSupportHours($at)) {
            $incident = $this->assignmentService->assignViaRoundRobinAfterGracePeriod($incident, $actor);

            if ($incident->assigned_to_user_id !== null) {
                return $incident;
            }

            return $this->assignShiftAdminFallback($incident, $actor, $at, 'no_active_support_agents');
        }

        return $this->assignShiftAdminDirect($incident, $actor, $at);
    }

    private function assignShiftAdminDirect(Incident $incident, User $actor, Carbon $at): Incident
    {
        $assignee = $this->assignmentService->resolveAssigneeOrNull($at);

        if ($assignee === null) {
            return $incident;
        }

        return $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: [
                'assignment_method' => 'incoming_email',
                'assignment_override' => true,
                'override_reason' => 'after_hours_shift_admin',
            ],
        );
    }

    private function assignShiftAdminFallback(
        Incident $incident,
        User $actor,
        Carbon $at,
        string $reason,
    ): Incident {
        $assignee = $this->assignmentService->resolveAssigneeOrNull($at);

        if ($assignee === null) {
            return $incident;
        }

        $assigned = $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: [
                'assignment_method' => 'incoming_email',
                'assignment_override' => true,
                'override_reason' => 'shift_admin_fallback',
            ],
        );

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.assignment_fallback',
            auditable: $assigned,
            newValues: [
                'reason' => $reason,
                'assigned_to_user_id' => $assigned->assigned_to_user_id,
            ],
        );

        return $assigned;
    }

    private function isWithinSupportHours(Carbon $at): bool
    {
        $localized = $at->copy()->timezone($this->settingService->get('assignment.timezone', config('app.timezone')));
        $time = $localized->format('H:i');
        $start = $this->settingService->get('assignment.day_shift_start', '09:00');
        $end = $this->settingService->get('assignment.day_shift_end', '18:30');

        return $time >= $start && $time <= $end;
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
