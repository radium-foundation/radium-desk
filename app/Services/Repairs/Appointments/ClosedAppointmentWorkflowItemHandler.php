<?php

namespace App\Services\Repairs\Appointments;

use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\SupportAppointment;
use App\Services\AutomationIdentityService;
use App\Services\IncidentWaitingStateService;
use App\Services\Operations\DeferredSmartAssignmentService;
use App\Services\Operations\SupportAppointmentSmartAssignmentService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseStatusService;
use App\Services\SettingService;
use App\Services\SupportAppointmentBookingWorkflowService;
use App\Support\Repair\Contracts\AbstractRepairItemHandler;
use App\Support\Repair\Core\RepairContext;
use App\Support\Repair\Data\RepairActionOutcome;
use App\Support\Repair\Data\RepairCandidate;
use App\Support\Repair\Data\RepairClassification;
use App\Support\Repair\Enums\RepairItemOutcome;
class ClosedAppointmentWorkflowItemHandler extends AbstractRepairItemHandler
{
    public const EVENT_REPAIRED = 'service_case.appointment_workflow_repaired';

    public function __construct(
        private readonly SupportAppointmentBookingWorkflowService $bookingWorkflowService,
        private readonly SupportAppointmentSmartAssignmentService $smartAssignmentService,
        private readonly ServiceCaseStatusService $statusService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly DeferredSmartAssignmentService $deferredSmartAssignmentService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly SettingService $settingService,
        private readonly \App\Services\AuditLogService $auditLogService,
    ) {}

    public function preview(
        RepairCandidate $candidate,
        RepairClassification $classification,
        RepairContext $context,
    ): RepairActionOutcome {
        $outcome = $classification->action === 'cleanup'
            ? RepairItemOutcome::WouldCleanup
            : RepairItemOutcome::WouldRepair;

        return RepairActionOutcome::would(
            outcome: $outcome,
            action: $classification->action,
            category: $classification->category,
            messages: [
                sprintf(
                    'Would %s closed case %s (appointment %s)',
                    $classification->action,
                    $candidate->subjectKey,
                    $candidate->relatedId() ?? 'n/a',
                ),
            ],
        );
    }

    public function execute(
        RepairCandidate $candidate,
        RepairClassification $classification,
        RepairContext $context,
    ): RepairActionOutcome {
        /** @var Incident $incident */
        $incident = Incident::query()
            ->whereKey($candidate->subjectId())
            ->lockForUpdate()
            ->with(['assignee', 'order', 'supportAppointments', 'activeWaitingState'])
            ->firstOrFail();

        /** @var SupportAppointment $appointment */
        $appointment = SupportAppointment::query()
            ->whereKey($candidate->relatedId())
            ->lockForUpdate()
            ->firstOrFail();

        $actor = $this->automationIdentity->systemUser();

        if ($classification->action === 'cleanup') {
            return $this->executeCleanup($incident, $appointment, $classification, $actor);
        }

        return $this->executeFull($incident, $appointment, $classification, $actor);
    }

    public function captureSnapshot(RepairCandidate $candidate): array
    {
        /** @var Incident $incident */
        $incident = $candidate->subject->fresh(['activeWaitingState']) ?? $candidate->subject;
        /** @var SupportAppointment|null $appointment */
        $appointment = $candidate->related?->fresh() ?? $candidate->related;

        $waiting = $incident->activeWaitingState;

        return [
            'incident' => [
                'status' => $incident->status?->value ?? (string) $incident->status,
                'assigned_to_user_id' => $incident->assigned_to_user_id,
                'pending_smart_assignment' => (bool) $incident->pending_smart_assignment,
                'assignment_origin' => $incident->assignment_origin?->value
                    ?? $incident->assignment_origin,
            ],
            'appointment' => [
                'id' => $appointment?->id,
                'status' => $appointment?->status?->value ?? $appointment?->status,
            ],
            'waiting_state' => $waiting === null ? null : [
                'id' => $waiting->id,
                'cleared_at' => $waiting->cleared_at?->toIso8601String(),
            ],
        ];
    }

    public function restoreSnapshot(
        RepairCandidate $candidate,
        array $before,
        RepairContext $context,
    ): void {
        /** @var Incident $incident */
        $incident = Incident::query()->whereKey($candidate->subjectId())->lockForUpdate()->firstOrFail();
        $incidentData = $before['incident'] ?? [];
        $appointmentData = $before['appointment'] ?? [];

        $incident->update([
            'status' => $incidentData['status'] ?? $incident->status,
            'assigned_to_user_id' => $incidentData['assigned_to_user_id'] ?? null,
            'pending_smart_assignment' => (bool) ($incidentData['pending_smart_assignment'] ?? false),
            'assignment_origin' => $incidentData['assignment_origin'] ?? $incident->assignment_origin,
        ]);

        if (isset($appointmentData['id'], $appointmentData['status'])) {
            SupportAppointment::query()
                ->whereKey($appointmentData['id'])
                ->update(['status' => $appointmentData['status']]);
        }

        $waiting = $before['waiting_state'] ?? null;
        if (is_array($waiting) && ($waiting['cleared_at'] ?? null) === null && isset($waiting['id'])) {
            IncidentWaitingState::query()
                ->whereKey($waiting['id'])
                ->update(['cleared_at' => null]);
        }
    }

    public function isIdempotentNoOp(
        RepairCandidate $candidate,
        RepairClassification $classification,
    ): bool {
        /** @var Incident $incident */
        $incident = $candidate->subject->fresh(['supportAppointments']) ?? $candidate->subject;

        if ($classification->action === 'full' && $incident->status !== IncidentStatus::Closed) {
            $hasScheduled = $incident->hasActiveSupportAppointment();

            return $hasScheduled && $incident->assigned_to_user_id !== null
                && ! $incident->pending_smart_assignment;
        }

        if ($classification->action === 'cleanup') {
            /** @var SupportAppointment|null $appointment */
            $appointment = $candidate->related?->fresh();

            return $appointment !== null
                && $appointment->status === SupportAppointmentStatus::Completed
                && $incident->status === IncidentStatus::Closed;
        }

        return false;
    }

    public function afterBatch(RepairContext $context): void
    {
        if (! $context->isExecute()) {
            return;
        }

        if ((bool) $context->options->extra('run_deferred', true)) {
            $this->deferredSmartAssignmentService->processPendingBatch();
        }
    }

    private function executeFull(
        Incident $incident,
        SupportAppointment $appointment,
        RepairClassification $classification,
        $actor,
    ): RepairActionOutcome {
        if ($incident->status !== IncidentStatus::Closed) {
            return RepairActionOutcome::skipped(
                action: 'full',
                category: $classification->category,
                reason: 'already_open',
            );
        }

        if ($appointment->status !== SupportAppointmentStatus::Scheduled) {
            return RepairActionOutcome::skipped(
                action: 'full',
                category: $classification->category,
                reason: 'appointment_not_scheduled',
            );
        }

        $incident = $this->bookingWorkflowService->reopenClosedIncidentIfNeeded(
            incident: $incident,
            appointment: $appointment,
            actor: $actor,
        );

        $incident = $this->smartAssignmentService->assignForActiveSupport(
            incident: $incident->fresh(['assignee', 'order', 'supportAppointments']),
            actor: $actor,
            appointment: $appointment,
        );

        $this->waitingStateService->clearActiveIfPresent($incident, $actor);

        $this->auditLogService->log(
            userId: $actor->id,
            event: self::EVENT_REPAIRED,
            auditable: $incident,
            oldValues: ['status' => IncidentStatus::Closed->value],
            newValues: [
                'repair_action' => 'full',
                'repair_category' => $classification->category,
                'appointment_id' => $appointment->id,
                'status' => $incident->status?->value,
                'assigned_to_user_id' => $incident->assigned_to_user_id,
                'pending_smart_assignment' => (bool) $incident->pending_smart_assignment,
                'skip_notifications' => true,
                'message' => 'Historical repair — appointment workflow backfill',
            ],
        );

        return RepairActionOutcome::success(
            outcome: RepairItemOutcome::Repaired,
            action: 'full',
            category: $classification->category,
            messages: ['Reopened and smart-assigned'],
            afterSnapshot: $this->captureSnapshot(new RepairCandidate(
                subject: $incident->fresh(),
                subjectKey: (string) $incident->reference_no,
                related: $appointment->fresh(),
            )),
        );
    }

    private function executeCleanup(
        Incident $incident,
        SupportAppointment $appointment,
        RepairClassification $classification,
        $actor,
    ): RepairActionOutcome {
        $completed = $this->statusService->completeScheduledSupportAppointments($incident);

        if ($this->isShiftAdminAssignee($incident)) {
            $this->assignmentService->clearAssigneeForPendingSmartAssignment(
                incident: $incident,
                actor: $actor,
                auditContext: [
                    'reason' => 'appointment_workflow_repair_cleanup',
                    'appointment_id' => $appointment->id,
                ],
            );
        }

        $incident->refresh();
        if ($incident->pending_smart_assignment) {
            $incident->update([
                'pending_smart_assignment' => false,
                'updated_by' => $actor->id,
            ]);
        }

        $this->auditLogService->log(
            userId: $actor->id,
            event: self::EVENT_REPAIRED,
            auditable: $incident,
            oldValues: [
                'appointment_status' => SupportAppointmentStatus::Scheduled->value,
            ],
            newValues: [
                'repair_action' => 'cleanup',
                'repair_category' => $classification->category,
                'appointment_id' => $appointment->id,
                'appointments_completed' => $completed,
                'skip_notifications' => true,
                'message' => 'Historical repair — stale scheduled appointment cleanup',
            ],
        );

        return RepairActionOutcome::success(
            outcome: RepairItemOutcome::CleanedUp,
            action: 'cleanup',
            category: $classification->category,
            messages: ['Completed scheduled appointment(s); case left closed'],
            afterSnapshot: $this->captureSnapshot(new RepairCandidate(
                subject: $incident->fresh(),
                subjectKey: (string) $incident->reference_no,
                related: $appointment->fresh(),
            )),
        );
    }

    private function isShiftAdminAssignee(Incident $incident): bool
    {
        $assigneeId = $incident->assigned_to_user_id;
        if ($assigneeId === null) {
            return false;
        }

        $adminIds = array_filter([
            $this->settingService->getInt('assignment.day_shift_admin_user_id'),
            $this->settingService->getInt('assignment.night_shift_admin_user_id'),
        ]);

        return in_array((int) $assigneeId, array_map('intval', $adminIds), true);
    }
}
