<?php

namespace App\Services\Operations;

use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;

class TeamWorkScheduleService
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function scheduleFor(User $user): ?TeamMemberWorkSchedule
    {
        return $this->workCalendarService->scheduleFor($user);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertForUser(User $user, array $data): TeamMemberWorkSchedule
    {
        $weeklyOffDays = collect($data['weekly_off_days'] ?? [])
            ->map(fn ($day): int => (int) $day)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return TeamMemberWorkSchedule::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'work_start_time' => $this->normalizeTime($data['work_start_time']),
                'work_end_time' => $this->normalizeTime($data['work_end_time']),
                'lunch_start_time' => filled($data['lunch_start_time'] ?? null)
                    ? $this->normalizeTime($data['lunch_start_time'])
                    : null,
                'lunch_end_time' => filled($data['lunch_end_time'] ?? null)
                    ? $this->normalizeTime($data['lunch_end_time'])
                    : null,
                'short_break_count' => (int) ($data['short_break_count'] ?? 0),
                'short_break_minutes' => (int) ($data['short_break_minutes'] ?? 10),
                'weekly_off_days' => $weeklyOffDays !== []
                    ? $weeklyOffDays
                    : $this->workCalendarService->defaultWeeklyOffDays(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(User $user): array
    {
        $schedule = $this->scheduleFor($user);

        if ($schedule === null) {
            return [
                'configured' => false,
                'work_start_time' => config('workforce_calendar.default_work_start', '09:00'),
                'work_end_time' => config('workforce_calendar.default_work_end', '18:00'),
                'lunch_start_time' => config('workforce_calendar.default_lunch_start'),
                'lunch_end_time' => config('workforce_calendar.default_lunch_end'),
                'short_break_count' => (int) config('workforce_calendar.default_short_break_count', 2),
                'short_break_minutes' => (int) config('workforce_calendar.default_short_break_minutes', 10),
                'weekly_off_days' => $this->workCalendarService->defaultWeeklyOffDays(),
            ];
        }

        return [
            'configured' => true,
            'work_start_time' => $this->displayTime($schedule->work_start_time),
            'work_end_time' => $this->displayTime($schedule->work_end_time),
            'lunch_start_time' => $schedule->lunch_start_time !== null
                ? $this->displayTime($schedule->lunch_start_time)
                : null,
            'lunch_end_time' => $schedule->lunch_end_time !== null
                ? $this->displayTime($schedule->lunch_end_time)
                : null,
            'short_break_count' => $schedule->short_break_count,
            'short_break_minutes' => $schedule->short_break_minutes,
            'weekly_off_days' => $schedule->weekly_off_days ?? $this->workCalendarService->defaultWeeklyOffDays(),
            'expected_working_minutes' => $this->workCalendarService->expectedWorkingMinutes($schedule),
        ];
    }

    private function normalizeTime(string $time): string
    {
        return Carbon::createFromFormat('H:i', $time)->format('H:i:s');
    }

    private function displayTime(mixed $time): string
    {
        return Carbon::today()->setTimeFromTimeString((string) $time)->format('H:i');
    }
}
