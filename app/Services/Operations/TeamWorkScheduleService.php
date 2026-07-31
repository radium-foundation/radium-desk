<?php

namespace App\Services\Operations;

use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TeamWorkScheduleService
{
    public const AUDIT_EVENT_SUPERSEDED = 'work_schedule.superseded';

    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function scheduleFor(User $user, ?Carbon $date = null): ?TeamMemberWorkSchedule
    {
        return $this->workCalendarService->scheduleFor($user, $date);
    }

    /**
     * Create or supersede the user's schedule with an effective-dated version.
     *
     * Never overwrites historical rows. Closes the open-ended current version
     * (effective_to = effective_from - 1 day) and inserts a new open version.
     *
     * Does not recalculate attendance or overtime.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsertForUser(User $user, array $data, ?int $actorUserId = null): TeamMemberWorkSchedule
    {
        $effectiveFrom = $this->resolveEffectiveFrom($data);
        $weeklyOffDays = $this->workCalendarService->normalizeWeeklyOffDays(
            isset($data['weekly_off_days']) && is_array($data['weekly_off_days'])
                ? $data['weekly_off_days']
                : null,
        );

        $attributes = [
            'work_start_time' => $this->normalizeTime((string) $data['work_start_time']),
            'work_end_time' => $this->normalizeTime((string) $data['work_end_time']),
            'lunch_start_time' => filled($data['lunch_start_time'] ?? null)
                ? $this->normalizeTime((string) $data['lunch_start_time'])
                : null,
            'lunch_end_time' => filled($data['lunch_end_time'] ?? null)
                ? $this->normalizeTime((string) $data['lunch_end_time'])
                : null,
            'short_break_count' => (int) ($data['short_break_count'] ?? 0),
            'short_break_minutes' => (int) ($data['short_break_minutes'] ?? 10),
            'weekly_off_days' => $weeklyOffDays,
        ];

        return DB::transaction(function () use ($user, $attributes, $effectiveFrom, $actorUserId): TeamMemberWorkSchedule {
            $current = TeamMemberWorkSchedule::query()
                ->where('user_id', $user->id)
                ->current()
                ->lockForUpdate()
                ->orderByDesc('effective_from')
                ->first();

            $priorSnapshot = null;

            if ($current === null) {
                $created = $this->createVersion($user, $attributes, $effectiveFrom, $actorUserId);
                $this->auditSupersede($actorUserId, $created, null, $created);

                return $created;
            }

            if ($effectiveFrom->lt($current->effective_from->copy()->startOfDay())) {
                throw new InvalidArgumentException(
                    'Effective from cannot be before the current schedule version start date ('.
                    $current->effective_from->toDateString().').',
                );
            }

            // Same-day edit of the open version that starts today: replace attributes
            // without creating a gap, still without rewriting closed history.
            if ($effectiveFrom->equalTo($current->effective_from->copy()->startOfDay())) {
                $priorSnapshot = $current->auditSnapshot();
                $this->applyAttributes($current, $attributes);
                $current->created_by = $actorUserId ?? $current->created_by;
                $current->expected_working_minutes = $this->workCalendarService->expectedWorkingMinutes($current);
                $current->save();
                $this->auditSupersede($actorUserId, $current, $priorSnapshot, $current);

                return $current->fresh() ?? $current;
            }

            $closeTo = $effectiveFrom->copy()->subDay();

            if ($closeTo->lt($current->effective_from->copy()->startOfDay())) {
                throw new InvalidArgumentException(
                    'Effective from leaves no valid window for the previous schedule version.',
                );
            }

            $priorSnapshot = $current->auditSnapshot();
            $current->effective_to = $closeTo->toDateString();
            $current->save();

            $created = $this->createVersion($user, $attributes, $effectiveFrom, $actorUserId);
            $this->auditSupersede($actorUserId, $created, $priorSnapshot, $created);

            return $created;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(User $user): array
    {
        $schedule = $this->scheduleFor($user, now());

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
                'effective_from' => now()->toDateString(),
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
            'weekly_off_days' => $this->workCalendarService->resolvedWeeklyOffDays($schedule),
            'expected_working_minutes' => $schedule->expected_working_minutes
                ?? $this->workCalendarService->expectedWorkingMinutes($schedule),
            'effective_from' => $schedule->effective_from?->toDateString() ?? now()->toDateString(),
            'effective_to' => $schedule->effective_to?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveEffectiveFrom(array $data): Carbon
    {
        $raw = $data['effective_from'] ?? null;

        if (filled($raw)) {
            return Carbon::parse((string) $raw)->startOfDay();
        }

        return now()->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createVersion(
        User $user,
        array $attributes,
        Carbon $effectiveFrom,
        ?int $actorUserId,
    ): TeamMemberWorkSchedule {
        $schedule = new TeamMemberWorkSchedule([
            'user_id' => $user->id,
            'effective_from' => $effectiveFrom->toDateString(),
            'effective_to' => null,
            'created_by' => $actorUserId,
            ...$attributes,
        ]);
        $schedule->expected_working_minutes = $this->workCalendarService->expectedWorkingMinutes($schedule);
        $schedule->save();

        return $schedule;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyAttributes(TeamMemberWorkSchedule $schedule, array $attributes): void
    {
        $schedule->fill($attributes);
    }

    /**
     * @param  array<string, mixed>|null  $priorSnapshot
     */
    private function auditSupersede(
        ?int $actorUserId,
        TeamMemberWorkSchedule $auditable,
        ?array $priorSnapshot,
        TeamMemberWorkSchedule $newSchedule,
    ): void {
        $this->auditLogService->log(
            userId: $actorUserId,
            event: self::AUDIT_EVENT_SUPERSEDED,
            auditable: $auditable,
            oldValues: $priorSnapshot,
            newValues: [
                'effective_from' => $newSchedule->effective_from?->toDateString(),
                'schedule' => $newSchedule->auditSnapshot(),
            ],
        );
    }

    private function normalizeTime(string $time): string
    {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) === 1) {
            return $time;
        }

        return Carbon::createFromFormat('H:i', $time)->format('H:i:s');
    }

    private function displayTime(mixed $time): string
    {
        return Carbon::today()->setTimeFromTimeString((string) $time)->format('H:i');
    }
}
