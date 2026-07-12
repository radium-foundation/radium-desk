<?php

namespace App\Services\Operations;

use App\Data\Operations\AppointmentReminderConfiguration;
use App\Data\Operations\SupportAppointmentReminderCandidate;
use App\Data\Operations\SupportAppointmentReminderDiagnosticCollector;
use App\Data\Operations\SupportAppointmentReminderDiagnosticEntry;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\SupportAppointment;
use App\Models\User;
use Illuminate\Support\Carbon;

class SupportAppointmentReminderService
{
    public function __construct(
        private readonly AppointmentReminderConfigurationResolver $configurationResolver,
        private readonly TeamTelegramQuietRulesService $quietRules,
    ) {}

    /**
     * @return list<SupportAppointmentReminderCandidate>
     */
    public function dueReminders(
        ?Carbon $at = null,
        ?SupportAppointmentReminderDiagnosticCollector $collector = null,
    ): array {
        $at ??= now();
        $today = $at->copy()->startOfDay();

        if ($collector !== null) {
            $collector->scheduledAppointments = SupportAppointment::query()->scheduled()->count();
            $collector->globalEnabled = $this->configurationResolver->globalEnabled();
        }

        if (! $this->configurationResolver->globalEnabled()) {
            return [];
        }

        $candidates = [];

        $appointments = SupportAppointment::query()
            ->scheduled()
            ->whereDate('preferred_date', $today)
            ->with(['incident.assignee', 'incident.order'])
            ->get();

        if ($collector !== null) {
            $collector->todaysAppointments = $appointments->count();
        }

        foreach ($appointments as $appointment) {
            $checks = [
                'Scheduled' => true,
                'Today' => true,
                'Assigned engineer' => false,
                'Quiet rules' => false,
                'Valid slot configuration' => false,
                'Threshold window' => false,
            ];
            $details = [];
            $failureReason = null;

            $incident = $appointment->incident;
            $engineer = $incident?->assignee;

            if ($engineer === null || ! $engineer->is_active) {
                $failureReason = $engineer === null
                    ? 'No assigned engineer'
                    : 'Engineer inactive';
                $this->recordVerbose($collector, $appointment->id, $checks, $failureReason, $details, $at);

                continue;
            }

            $checks['Assigned engineer'] = true;

            if ($collector !== null) {
                $collector->withAssignedEngineer++;
            }

            $configuration = $this->configurationResolver->forUser($engineer);

            if ($configuration->isDisabled()) {
                $failureReason = 'Reminder configuration disabled';
                $this->recordVerbose($collector, $appointment->id, $checks, $failureReason, $details, $at);

                continue;
            }

            if (! $this->quietRules->shouldSendAppointmentReminder($engineer, $at)) {
                $failureReason = $this->quietRules->appointmentReminderExclusionReason($engineer, $at)
                    ?? 'Quiet rules';
                $this->recordVerbose($collector, $appointment->id, $checks, $failureReason, $details, $at);

                continue;
            }

            $checks['Quiet rules'] = true;

            if ($collector !== null) {
                $collector->passedQuietRules++;
            }

            $slot = $appointment->preferred_time_slot;

            if (! $slot instanceof SupportAppointmentTimeSlot) {
                $failureReason = 'Invalid time slot';
                $this->recordVerbose($collector, $appointment->id, $checks, $failureReason, $details, $at);

                continue;
            }

            $startsAt = $this->quietRules->slotStartAt($slot, $today);

            if ($startsAt === null) {
                $failureReason = 'Slot start time not configured';
                $this->recordVerbose($collector, $appointment->id, $checks, $failureReason, $details, $at);

                continue;
            }

            $checks['Valid slot configuration'] = true;

            if ($collector !== null) {
                $collector->validSlotConfiguration++;
            }

            $matchedThreshold = false;

            foreach ($configuration->thresholdsMinutes as $threshold) {
                if (! $this->isThresholdDue($startsAt, $threshold, $at)) {
                    continue;
                }

                $matchedThreshold = true;

                $candidates[] = new SupportAppointmentReminderCandidate(
                    appointment: $appointment,
                    engineer: $engineer,
                    thresholdMinutes: $threshold,
                    startsAt: $startsAt,
                );
            }

            if ($matchedThreshold) {
                $checks['Threshold window'] = true;
            } else {
                $details = $this->thresholdDetails($slot, $startsAt, $at);
            }

            $this->recordVerbose(
                collector: $collector,
                appointmentId: $appointment->id,
                checks: $checks,
                failureReason: $matchedThreshold ? null : null,
                details: $matchedThreshold ? $details : $this->thresholdDetails($slot, $startsAt, $at),
                at: $at,
            );
        }

        if ($collector !== null) {
            $collector->matchedReminderWindow = count($candidates);
        }

        return $candidates;
    }

    public function isThresholdDue(Carbon $startsAt, int $thresholdMinutes, Carbon $at): bool
    {
        $minutesUntil = (int) $at->diffInMinutes($startsAt, false);

        if ($thresholdMinutes === 0) {
            return $minutesUntil <= 0 && $minutesUntil >= -5;
        }

        return $minutesUntil <= $thresholdMinutes && $minutesUntil > ($thresholdMinutes - 2);
    }

    public function configurationFor(User $user): AppointmentReminderConfiguration
    {
        return $this->configurationResolver->forUser($user);
    }

    /**
     * @param  array<string, bool>  $checks
     * @param  array<string, string>  $details
     */
    private function recordVerbose(
        ?SupportAppointmentReminderDiagnosticCollector $collector,
        int $appointmentId,
        array $checks,
        ?string $failureReason,
        array $details,
        Carbon $at,
    ): void {
        if ($collector === null || ! $collector->verbose) {
            return;
        }

        $collector->verboseEntries[] = new SupportAppointmentReminderDiagnosticEntry(
            appointmentId: $appointmentId,
            checks: $checks,
            failureReason: $failureReason,
            details: array_merge($details, [
                'Current time' => $at->format('H:i'),
            ]),
        );
    }

    /**
     * @return array<string, string>
     */
    private function thresholdDetails(
        SupportAppointmentTimeSlot $slot,
        Carbon $startsAt,
        Carbon $at,
    ): array {
        $slotTime = config('team_telegram.support_slots.'.$slot->value);
        $slotTimeLabel = is_string($slotTime) && $slotTime !== ''
            ? $slotTime
            : $startsAt->format('H:i');

        return [
            'Slot' => sprintf('%s (%s)', $slot->label(), $slotTimeLabel),
            'Minutes until slot' => (string) (int) $at->diffInMinutes($startsAt, false),
        ];
    }
}
