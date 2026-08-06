<?php

namespace App\Services\Operations;

use App\Data\Operations\SmartAssignmentResult;
use App\Enums\AssignmentOrigin;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Notifications\SmartAssignmentUnassignedNotification;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SupportAppointmentBookingWorkflowService;
use App\Support\Repair\Core\RepairContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;

class SupportAppointmentSmartAssignmentService
{
    public function __construct(
        private readonly SmartAssignmentService $smartAssignmentService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function assignAfterBooking(
        Incident $incident,
        SupportAppointment $appointment,
        ?User $actor = null,
    ): Incident {
        return $this->assignForActiveSupport($incident, $actor, $appointment);
    }

    public function assignForActiveSupport(
        Incident $incident,
        ?User $actor = null,
        ?SupportAppointment $appointment = null,
    ): Incident {
        $incident = $incident->fresh(['assignee', 'supportAppointments', 'order']);

        $appointment ??= $incident->supportAppointments
            ->first(fn (SupportAppointment $candidate): bool => $candidate->isScheduled());

        if ($appointment === null) {
            return $incident;
        }

        $actor ??= $this->automationIdentity->systemUser();
        $currentAssignee = $incident->assignee;

        // Support engineers keep ownership. Ready Queue admins must not.
        if ($currentAssignee !== null && $this->assignmentService->shouldRetainOperationalAssignee($incident)) {
            return $incident;
        }

        if (! config('smart_assignment.enabled', true)) {
            return $this->handleUnassigned(
                incident: $incident,
                appointment: $appointment,
                actor: $actor,
                result: SmartAssignmentResult::unassigned('smart_assignment_disabled'),
            );
        }

        $result = $this->smartAssignmentService->resolveBestAssignee(order: $incident->order);

        if (! $result->isAssigned()) {
            return $this->handleUnassigned($incident, $appointment, $actor, $result);
        }

        $assignee = $result->assignee;
        assert($assignee instanceof User);

        $isReassignment = $incident->assigned_to_user_id !== null;

        $incident = $this->clearPendingSmartAssignment($incident, $actor);

        $incident = $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: [
                'assignment_method' => 'smart',
                'assignment_reason' => [
                    'label' => SupportAppointmentBookingWorkflowService::ASSIGNMENT_REASON,
                    ...$result->context,
                ],
                'assignment_trigger' => 'support_appointment_booked',
                'appointment_id' => $appointment->id,
                'reason' => SupportAppointmentBookingWorkflowService::ASSIGNMENT_REASON,
            ],
            event: $isReassignment ? 'service_case.reassigned' : 'service_case.assigned',
            assignmentOrigin: AssignmentOrigin::AppointmentSmartAssignment,
        );

        event(new SupportAppointmentSmartAssigned(
            incident: $incident,
            appointment: $appointment,
            assignee: $assignee,
            result: $result,
        ));

        return $incident;
    }

    public function clearPendingSmartAssignment(Incident $incident, User $actor): Incident
    {
        if (! $incident->pending_smart_assignment) {
            return $incident;
        }

        $incident->update([
            'pending_smart_assignment' => false,
            'updated_by' => $actor->id,
        ]);

        return $incident->fresh(['assignee', 'order', 'supportAppointments']);
    }

    private function handleUnassigned(
        Incident $incident,
        SupportAppointment $appointment,
        User $actor,
        SmartAssignmentResult $result,
    ): Incident {
        $alreadyPending = (bool) $incident->pending_smart_assignment;
        $previousAssigneeId = $incident->assigned_to_user_id;

        if (! $alreadyPending) {
            if ($previousAssigneeId !== null) {
                $incident = $this->assignmentService->clearAssigneeForPendingSmartAssignment(
                    incident: $incident,
                    actor: $actor,
                    auditContext: [
                        'assignment_method' => 'smart',
                        'assignment_trigger' => 'support_appointment_booked',
                        'appointment_id' => $appointment->id,
                        'assignment_reason' => [
                            'label' => SupportAppointmentBookingWorkflowService::ASSIGNMENT_REASON,
                            ...$result->context,
                        ],
                        'reason' => 'no_eligible_support_engineer',
                        'assignment_origin' => AssignmentOrigin::AppointmentSmartAssignment->value,
                    ],
                );
            }

            $incident->update([
                'pending_smart_assignment' => true,
                'assignment_origin' => AssignmentOrigin::AppointmentSmartAssignment,
                'updated_by' => $actor->id,
            ]);

            $incident = $incident->fresh(['assignee', 'order']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'service_case.smart_assignment_unassigned',
                auditable: $incident,
                oldValues: [
                    'assigned_to_user_id' => $previousAssigneeId,
                ],
                newValues: [
                    'assigned_to_user_id' => null,
                    'assignment_method' => 'smart',
                    'assignment_trigger' => 'support_appointment_booked',
                    'appointment_id' => $appointment->id,
                    'assignment_reason' => [
                        'label' => SupportAppointmentBookingWorkflowService::ASSIGNMENT_REASON,
                        ...$result->context,
                    ],
                    'reason' => SupportAppointmentBookingWorkflowService::ASSIGNMENT_REASON,
                    'queue' => 'scheduled',
                    'ownership_retained' => false,
                    'assignment_origin' => AssignmentOrigin::AppointmentSmartAssignment->value,
                    'status_label' => 'Pending Support Assignment',
                ],
            );

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'service_case.pending_smart_assignment',
                auditable: $incident,
                oldValues: [
                    'pending_smart_assignment' => false,
                    'assigned_to_user_id' => $previousAssigneeId,
                ],
                newValues: [
                    'pending_smart_assignment' => true,
                    'assigned_to_user_id' => null,
                    'assignment_method' => 'smart',
                    'assignment_trigger' => 'support_appointment_booked',
                    'appointment_id' => $appointment->id,
                    'assignment_reason' => [
                        'label' => SupportAppointmentBookingWorkflowService::ASSIGNMENT_REASON,
                        ...$result->context,
                    ],
                    'reason' => SupportAppointmentBookingWorkflowService::ASSIGNMENT_REASON,
                    'queue' => 'scheduled',
                    'assignment_origin' => AssignmentOrigin::AppointmentSmartAssignment->value,
                    'status_label' => 'Pending Support Assignment',
                ],
            );

            $this->alertOperationsAdmins($incident, $appointment, $result);
        }

        Log::warning('smart_assignment.unassigned', [
            'incident_id' => $incident->id,
            'appointment_id' => $appointment->id,
            'reason' => $result->context['reason'] ?? 'unknown',
            'already_pending' => $alreadyPending,
        ]);

        return $incident->fresh(['assignee']);
    }

    private function alertOperationsAdmins(
        Incident $incident,
        SupportAppointment $appointment,
        SmartAssignmentResult $result,
    ): void {
        if (app()->bound(RepairContext::class) && app(RepairContext::class)->isSilent()) {
            return;
        }

        $admins = User::query()
            ->where('is_active', true)
            ->role(RolePermissionSeeder::ADMIN_TEAM_ROLES)
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new SmartAssignmentUnassignedNotification($incident, $appointment, $result));
        }
    }
}
