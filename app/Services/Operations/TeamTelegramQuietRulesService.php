<?php

namespace App\Services\Operations;

use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\WorkCalendarDayStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

class TeamTelegramQuietRulesService
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function shouldDeliver(User $user, ?Carbon $at = null): bool
    {
        if (! config('team_telegram.enabled', true)) {
            return false;
        }

        $at ??= now();
        $status = WorkCalendarDayStatus::tryFrom(
            (string) ($this->workCalendarService->todayStatusFor($user, $at)['status'] ?? ''),
        );

        if ($status === null) {
            return false;
        }

        return ! in_array($status, [
            WorkCalendarDayStatus::LeaveApproved,
            WorkCalendarDayStatus::Holiday,
            WorkCalendarDayStatus::WeeklyOff,
            WorkCalendarDayStatus::OutsideHours,
        ], true);
    }

    public function shouldSendDailyBriefing(User $user, ?Carbon $at = null): bool
    {
        if (! $this->shouldDeliver($user, $at)) {
            return false;
        }

        $at ??= now();
        $status = WorkCalendarDayStatus::tryFrom(
            (string) ($this->workCalendarService->todayStatusFor($user, $at)['status'] ?? ''),
        );

        if ($status !== WorkCalendarDayStatus::StartsLater) {
            return false;
        }

        $schedule = $this->workCalendarService->scheduleFor($user);

        if ($schedule === null) {
            return false;
        }

        $workStart = $this->workCalendarService->expectedWorkStartAt($schedule, $at);
        $windowMinutes = max(1, (int) config('team_telegram.daily_briefing.minutes_before_work_start', 60));
        $windowStart = $workStart->copy()->subMinutes($windowMinutes);

        return $at->gte($windowStart) && $at->lt($workStart);
    }

    public function shouldSendSlotReminder(
        User $user,
        SupportAppointmentTimeSlot $slot,
        ?Carbon $at = null,
    ): bool {
        if (! $this->shouldDeliver($user, $at)) {
            return false;
        }

        $at ??= now();
        $slotStart = $this->slotStartAt($slot, $at);

        if ($slotStart === null) {
            return false;
        }

        return $at->gte($slotStart) && $at->lt($slotStart->copy()->addHour());
    }

    public function shouldSendAppointmentReminder(User $user, ?Carbon $at = null): bool
    {
        return $this->appointmentReminderExclusionReason($user, $at) === null;
    }

    public function appointmentReminderExclusionReason(User $user, ?Carbon $at = null): ?string
    {
        if (! config('team_telegram.enabled', true)) {
            return 'Team Telegram disabled';
        }

        $at ??= now();
        $status = WorkCalendarDayStatus::tryFrom(
            (string) ($this->workCalendarService->todayStatusFor($user, $at)['status'] ?? ''),
        );

        if ($status === null) {
            return 'Work calendar status unavailable';
        }

        if (in_array($status, [
            WorkCalendarDayStatus::LeaveApproved,
            WorkCalendarDayStatus::Holiday,
            WorkCalendarDayStatus::WeeklyOff,
        ], true)) {
            return $status->label();
        }

        return null;
    }

    public function slotStartAt(SupportAppointmentTimeSlot $slot, Carbon $date): ?Carbon
    {
        return $this->slotBoundaryAt($slot, $date, 'support_slots');
    }

    public function slotEndAt(SupportAppointmentTimeSlot $slot, Carbon $date): ?Carbon
    {
        return $this->slotBoundaryAt($slot, $date, 'support_slot_ends');
    }

    private function slotBoundaryAt(SupportAppointmentTimeSlot $slot, Carbon $date, string $configKey): ?Carbon
    {
        $configured = config('team_telegram.'.$configKey.'.'.$slot->value);

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        return $date->copy()->startOfDay()->setTimeFromTimeString(
            strlen($configured) === 5 ? $configured.':00' : $configured,
        );
    }
}
