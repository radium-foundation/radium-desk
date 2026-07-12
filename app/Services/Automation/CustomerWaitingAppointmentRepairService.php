<?php

namespace App\Services\Automation;

use App\Data\CustomerWaitingAppointmentRepairSummary;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\IncidentWaitingStateService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * One-time repair: clear active waiting states where a scheduled appointment already exists.
 *
 * Never closes incidents or notifies customers.
 */
class CustomerWaitingAppointmentRepairService
{
    public const EVENT_WAITING_CLEARED = 'service_case.customer_waiting_appointment_repaired';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly IncidentWaitingStateService $waitingStateService,
    ) {}

    public function repair(bool $dryRun = true): CustomerWaitingAppointmentRepairSummary
    {
        $appointmentsFound = 0;
        $waitingStatesCleared = 0;
        $skipped = 0;
        $samples = [];
        $actor = null;

        $waitingStates = IncidentWaitingState::query()
            ->active()
            ->with(['incident.order', 'incident.supportAppointments'])
            ->whereHas('incident.supportAppointments')
            ->orderBy('id')
            ->get();

        foreach ($waitingStates as $waitingState) {
            $incident = $waitingState->incident;

            if ($incident === null) {
                $skipped++;
                continue;
            }

            $skipReason = $this->skipReason($incident, $waitingState);

            if ($skipReason !== null) {
                $skipped++;

                continue;
            }

            $appointmentsFound++;

            if (count($samples) < 20) {
                $samples[] = [
                    'action' => $dryRun ? 'would_clear' : 'clear_waiting',
                    'waiting_state_id' => $waitingState->id,
                    'incident_id' => $incident->id,
                    'order_id' => $incident->order?->order_id,
                    'waiting_reason' => $waitingState->waiting_reason->value,
                ];
            }

            if ($dryRun) {
                continue;
            }

            $actor = $this->resolveAutomationActor($actor);

            if ($actor === null) {
                return new CustomerWaitingAppointmentRepairSummary(
                    dryRun: $dryRun,
                    appointmentsFound: $appointmentsFound,
                    waitingStatesCleared: $waitingStatesCleared,
                    skipped: $skipped,
                    samples: $samples,
                    configurationError: $this->configurationErrorMessage(),
                );
            }

            $cleared = $this->waitingStateService->clearActiveIfPresent($incident, $actor);

            if ($cleared === null) {
                $skipped++;

                continue;
            }

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_WAITING_CLEARED,
                auditable: $incident,
                oldValues: [
                    'waiting_state_id' => $waitingState->id,
                    'waiting_reason' => $waitingState->waiting_reason->value,
                ],
                newValues: [
                    'cleared' => true,
                    'repair' => 'appointment_waiting',
                ],
            );

            $waitingStatesCleared++;
        }

        Log::info('Customer waiting appointment repair completed.', [
            'dry_run' => $dryRun,
            'appointments_found' => $appointmentsFound,
            'waiting_states_cleared' => $waitingStatesCleared,
            'skipped' => $skipped,
        ]);

        return new CustomerWaitingAppointmentRepairSummary(
            dryRun: $dryRun,
            appointmentsFound: $appointmentsFound,
            waitingStatesCleared: $waitingStatesCleared,
            skipped: $skipped,
            samples: $samples,
        );
    }

    private function skipReason(Incident $incident, IncidentWaitingState $waitingState): ?string
    {
        if (! $waitingState->isActive()) {
            return 'no_active_waiting_state';
        }

        if ($incident->status === IncidentStatus::Closed) {
            return 'incident_closed';
        }

        if (! $incident->isActive()) {
            return 'incident_not_operationally_active';
        }

        if (! $incident->hasActiveSupportAppointment()) {
            return 'no_active_appointment';
        }

        return null;
    }

    private function resolveAutomationActor(?User $actor): ?User
    {
        if ($actor !== null) {
            return $actor;
        }

        try {
            return $this->automationIdentity->systemUser();
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    private function configurationErrorMessage(): string
    {
        $configuredEmail = (string) config('cashfree.system_user_email');

        return sprintf(
            'Automation system user not found. Set CASHFREE_SYSTEM_USER_EMAIL to an existing user email (configured: %s).',
            $configuredEmail !== '' ? $configuredEmail : '(empty)',
        );
    }
}
