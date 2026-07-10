<?php

namespace App\Services\Operations;

use App\Models\Incident;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;

class BusinessHoursSlaCalculator
{
    public function __construct(
        private readonly WorkCalendarService $workCalendar,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('sla.business_hours_enabled', false);
    }

    public function elapsedBusinessHours(Incident $incident, ?Carbon $now = null): int
    {
        if ($incident->created_at === null) {
            return 0;
        }

        $now ??= now();
        [$user, $schedule] = $this->resolveCalendarContext($incident);

        return intdiv(
            $this->elapsedBusinessMinutes($incident->created_at, $now, $user, $schedule),
            60,
        );
    }

    public function elapsedBusinessMinutes(
        Carbon $from,
        Carbon $to,
        ?User $user,
        TeamMemberWorkSchedule $schedule,
    ): int {
        if ($from->gte($to)) {
            return 0;
        }

        $total = 0;
        $cursor = $from->copy();
        $guard = 0;

        while ($cursor->lt($to) && $guard++ < 1096) {
            $next = $this->advanceToNextBusinessInstant($cursor, $to, $user, $schedule);

            if ($next === null || $next->gte($to)) {
                break;
            }

            $segmentEnd = $this->findBusinessSegmentEnd($next, $to, $schedule);
            $total += max(0, (int) $next->diffInMinutes($segmentEnd));
            $cursor = $segmentEnd;
        }

        return $total;
    }

    public function defaultSchedule(): TeamMemberWorkSchedule
    {
        return new TeamMemberWorkSchedule([
            'work_start_time' => $this->configTime('workforce_calendar.default_work_start'),
            'work_end_time' => $this->configTime('workforce_calendar.default_work_end'),
            'lunch_start_time' => $this->configTime('workforce_calendar.default_lunch_start'),
            'lunch_end_time' => $this->configTime('workforce_calendar.default_lunch_end'),
            'short_break_count' => (int) config('workforce_calendar.default_short_break_count', 0),
            'short_break_minutes' => (int) config('workforce_calendar.default_short_break_minutes', 0),
            'weekly_off_days' => config('workforce_calendar.default_weekly_off_days', [Carbon::SUNDAY]),
        ]);
    }

    /**
     * @return array{0: User|null, 1: TeamMemberWorkSchedule}
     */
    private function resolveCalendarContext(Incident $incident): array
    {
        $assignee = $incident->relationLoaded('assignee')
            ? $incident->assignee
            : ($incident->assigned_to_user_id !== null ? $incident->assignee()->first() : null);

        if ($assignee !== null) {
            $schedule = $this->workCalendar->scheduleFor($assignee);

            if ($schedule !== null) {
                return [$assignee, $schedule];
            }
        }

        return [null, $this->defaultSchedule()];
    }

    private function advanceToNextBusinessInstant(
        Carbon $cursor,
        Carbon $to,
        ?User $user,
        TeamMemberWorkSchedule $schedule,
    ): ?Carbon {
        $probe = $cursor->copy();
        $attempts = 0;

        while ($probe->lt($to) && $attempts++ < 1096) {
            if ($this->isSkippableDay($user, $schedule, $probe)) {
                $probe = $this->workCalendar->expectedWorkStartAt(
                    $schedule,
                    $probe->copy()->startOfDay()->addDay(),
                );

                continue;
            }

            if ($this->workCalendar->isWithinWorkingHours($schedule, $probe)) {
                return $probe;
            }

            if ($this->workCalendar->isDuringLunch($schedule, $probe)) {
                $probe = $this->timeOnDate($schedule->lunch_end_time, $probe);

                continue;
            }

            $window = $this->workCalendar->resolveShiftWindow($schedule, $probe);

            if ($window === null) {
                $probe = $this->workCalendar->expectedWorkStartAt(
                    $schedule,
                    $probe->copy()->startOfDay()->addDay(),
                );

                continue;
            }

            [$shiftStart, $shiftEnd] = $window;

            if ($probe->lt($shiftStart)) {
                $probe = $shiftStart->copy();

                continue;
            }

            if ($probe->lt($shiftEnd)) {
                return $probe;
            }

            $probe = $this->workCalendar->expectedWorkStartAt(
                $schedule,
                $probe->copy()->startOfDay()->addDay(),
            );
        }

        return null;
    }

    private function findBusinessSegmentEnd(
        Carbon $from,
        Carbon $to,
        TeamMemberWorkSchedule $schedule,
    ): Carbon {
        $window = $this->workCalendar->resolveShiftWindow($schedule, $from);

        if ($window === null) {
            return $to->copy();
        }

        [, $shiftEnd] = $window;
        $candidate = $shiftEnd->lt($to) ? $shiftEnd->copy() : $to->copy();

        if ($schedule->lunch_start_time !== null && $schedule->lunch_end_time !== null) {
            $lunchStart = $this->timeOnDate($schedule->lunch_start_time, $from);

            if ($from->lt($lunchStart) && $lunchStart->lt($candidate)) {
                return $lunchStart;
            }
        }

        return $candidate;
    }

    private function isSkippableDay(
        ?User $user,
        TeamMemberWorkSchedule $schedule,
        Carbon $at,
    ): bool {
        if ($this->workCalendar->isCompanyHoliday($at)) {
            return true;
        }

        if ($user !== null && $this->workCalendar->hasApprovedLeave($user, $at)) {
            return true;
        }

        return ! $this->workCalendar->isWorkingDay($schedule, $at);
    }

    private function timeOnDate(mixed $time, Carbon $date): Carbon
    {
        $value = (string) $time;

        if (strlen($value) === 5) {
            $value .= ':00';
        }

        return $date->copy()->setTimeFromTimeString($value);
    }

    private function configTime(string $key): string
    {
        $value = (string) config($key, '00:00');

        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
