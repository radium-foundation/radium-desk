<?php

namespace App\Services\Operations;

use App\Data\Operations\AppointmentReminderConfiguration;
use App\Data\Operations\SupportAppointmentReminderCandidate;
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
    public function dueReminders(?Carbon $at = null): array
    {
        if (! $this->configurationResolver->globalEnabled()) {
            return [];
        }

        $at ??= now();
        $today = $at->copy()->startOfDay();
        $candidates = [];

        $appointments = SupportAppointment::query()
            ->scheduled()
            ->whereDate('preferred_date', $today)
            ->with(['incident.assignee', 'incident.order'])
            ->get();

        foreach ($appointments as $appointment) {
            $incident = $appointment->incident;
            $engineer = $incident?->assignee;

            if ($engineer === null || ! $engineer->is_active) {
                continue;
            }

            $configuration = $this->configurationResolver->forUser($engineer);

            if ($configuration->isDisabled()) {
                continue;
            }

            if (! $this->quietRules->shouldSendAppointmentReminder($engineer, $at)) {
                continue;
            }

            $slot = $appointment->preferred_time_slot;

            if (! $slot instanceof SupportAppointmentTimeSlot) {
                continue;
            }

            $startsAt = $this->quietRules->slotStartAt($slot, $today);

            if ($startsAt === null) {
                continue;
            }

            foreach ($configuration->thresholdsMinutes as $threshold) {
                if (! $this->isThresholdDue($startsAt, $threshold, $at)) {
                    continue;
                }

                $candidates[] = new SupportAppointmentReminderCandidate(
                    appointment: $appointment,
                    engineer: $engineer,
                    thresholdMinutes: $threshold,
                    startsAt: $startsAt,
                );
            }
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
}
