<?php

namespace App\Services\Operations;

use App\Data\Operations\SmartAssignmentResult;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Notifications\SmartAssignmentUnassignedNotification;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\ServiceCaseAssignmentService;
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
        if (! config('smart_assignment.enabled', true)) {
            return $incident->fresh(['assignee']);
        }

        $incident = $incident->fresh(['assignee', 'supportAppointments', 'order']);

        $appointment ??= $incident->supportAppointments
            ->first(fn (SupportAppointment $candidate): bool => $candidate->isScheduled());

        if ($appointment === null) {
            return $incident;
        }

        $currentAssignee = $incident->assignee;

        if ($currentAssignee !== null && $this->assignmentService->shouldRetainOperationalAssignee($incident)) {
            return $incident;
        }

        $actor ??= $this->automationIdentity->systemUser();
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
                'assignment_reason' => $result->context,
                'assignment_trigger' => 'support_appointment_booked',
                'appointment_id' => $appointment->id,
            ],
            event: $isReassignment ? 'service_case.reassigned' : 'service_case.assigned',
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

        if (! $alreadyPending) {
            // Keep current ownership (typically Shift Admin). Do not clear assigned_to_user_id.
            $currentAssigneeId = $incident->assigned_to_user_id;

            $incident->update([
                'pending_smart_assignment' => true,
                'updated_by' => $actor->id,
            ]);

            $incident = $incident->fresh(['assignee', 'order']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'service_case.smart_assignment_unassigned',
                auditable: $incident,
                oldValues: [
                    'assigned_to_user_id' => $currentAssigneeId,
                ],
                newValues: [
                    'assigned_to_user_id' => $currentAssigneeId,
                    'assignment_method' => 'smart',
                    'assignment_trigger' => 'support_appointment_booked',
                    'appointment_id' => $appointment->id,
                    'assignment_reason' => $result->context,
                    'queue' => 'scheduled',
                    'ownership_retained' => true,
                ],
            );

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'service_case.pending_smart_assignment',
                auditable: $incident,
                oldValues: [
                    'pending_smart_assignment' => false,
                    'assigned_to_user_id' => $currentAssigneeId,
                ],
                newValues: [
                    'pending_smart_assignment' => true,
                    'assigned_to_user_id' => $currentAssigneeId,
                    'assignment_method' => 'smart',
                    'assignment_trigger' => 'support_appointment_booked',
                    'appointment_id' => $appointment->id,
                    'assignment_reason' => $result->context,
                    'queue' => 'scheduled',
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
        $admins = User::query()
            ->where('is_active', true)
            ->role(RolePermissionSeeder::ADMIN_TEAM_ROLES)
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new SmartAssignmentUnassignedNotification($incident, $appointment, $result));
        }
    }
}
