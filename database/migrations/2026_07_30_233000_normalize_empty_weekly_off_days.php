<?php

use App\Models\TeamMemberWorkSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize empty / string-typed weekly_off_days so Sunday (company default)
 * is never silently treated as a working day.
 *
 * Business rule: every employee schedule must resolve to at least one weekly
 * off. Empty [] / null are treated as corrupt/unset — not as an intentional
 * seven-day work week — and are replaced with the company default.
 *
 * Non-empty valid offs (e.g. [1, 6] or ["0"]) are preserved after int
 * normalization. Only empty/null rows are rewritten to the company default.
 * String day values are coerced at read time by WorkCalendarService.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaultWeeklyOffDays = config('workforce_calendar.default_weekly_off_days', [0]);
        $defaultWeeklyOffDays = collect($defaultWeeklyOffDays)
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 0 && $day <= 6)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($defaultWeeklyOffDays === []) {
            $defaultWeeklyOffDays = [0];
        }

        TeamMemberWorkSchedule::query()
            ->orderBy('id')
            ->chunkById(100, function ($schedules) use ($defaultWeeklyOffDays): void {
                foreach ($schedules as $schedule) {
                    $raw = $schedule->weekly_off_days;

                    $normalized = collect(is_array($raw) ? $raw : [])
                        ->map(fn (mixed $day): int => (int) $day)
                        ->filter(fn (int $day): bool => $day >= 0 && $day <= 6)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                    $resolved = $normalized !== [] ? $normalized : $defaultWeeklyOffDays;

                    // Preserve intentional non-empty offs. Rewrite only empty/null → default.
                    if ($this->weeklyOffDaysEqual($raw, $resolved)) {
                        continue;
                    }

                    // Force JSON rewrite even when PHP arrays compare equal after cast quirks.
                    DB::table('team_member_work_schedules')
                        ->where('id', $schedule->id)
                        ->update([
                            'weekly_off_days' => json_encode(array_values($resolved)),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible data normalization.
    }

    /**
     * @param  mixed  $raw
     * @param  list<int>  $resolved
     */
    private function weeklyOffDaysEqual(mixed $raw, array $resolved): bool
    {
        if (! is_array($raw)) {
            return false;
        }

        $rawNormalized = collect($raw)
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 0 && $day <= 6)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $rawNormalized === $resolved && $rawNormalized !== [];
    }
};
