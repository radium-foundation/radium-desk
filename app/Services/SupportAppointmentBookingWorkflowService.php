<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupportAppointmentBookingWorkflowService
{
    public const EVENT_APPOINTMENT_BOOKED = 'support_appointment.booked';

    public const EVENT_APPOINTMENT_BOOKING_REOPENED = 'service_case.appointment_booking_reopened';

    public const ASSIGNMENT_REASON = 'Tech Support Appointment';

    public function __construct(
        private readonly ServiceCaseStatusService $statusService,
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function recordAppointmentBooked(
        Incident $incident,
        SupportAppointment $appointment,
        ?User $actor = null,
    ): void {
        $actor ??= $this->automationIdentity->systemUser();

        $this->auditLogService->log(
            userId: $actor->id,
            event: self::EVENT_APPOINTMENT_BOOKED,
            auditable: $incident,
            oldValues: null,
            newValues: [
                'appointment_id' => $appointment->id,
                'preferred_date' => $appointment->preferred_date?->toDateString(),
                'preferred_time_slot' => $appointment->preferred_time_slot?->value
                    ?? $appointment->preferred_time_slot,
                'status' => $appointment->status?->value ?? $appointment->status,
                'reason' => self::ASSIGNMENT_REASON,
                'message' => 'Tech Support appointment booked.',
            ],
        );
    }

    public function reopenClosedIncidentIfNeeded(
        Incident $incident,
        SupportAppointment $appointment,
        ?User $actor = null,
    ): Incident {
        if ($incident->status !== IncidentStatus::Closed) {
            return $incident;
        }

        $actor ??= $this->automationIdentity->systemUser();

        return DB::transaction(function () use ($incident, $appointment, $actor): Incident {
            $freshIncident = $this->statusService->reopen($incident, $actor);

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_APPOINTMENT_BOOKING_REOPENED,
                auditable: $freshIncident,
                oldValues: [
                    'status' => IncidentStatus::Closed->value,
                ],
                newValues: [
                    'status' => IncidentStatus::Open->value,
                    'appointment_id' => $appointment->id,
                    'reason' => 'support_appointment_booked_on_closed_case',
                    'message' => 'Case reopened automatically after Tech Support appointment booking.',
                ],
            );

            return $freshIncident;
        });
    }
}
