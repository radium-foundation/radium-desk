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

    public function slotStartAt(SupportAppointmentTimeSlot $slot, Carbon $date): ?Carbon
    {
        $configured = config('team_telegram.support_slots.'.$slot->value);

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        return $date->copy()->startOfDay()->setTimeFromTimeString(
            strlen($configured) === 5 ? $configured.':00' : $configured,
        );
    }
}
