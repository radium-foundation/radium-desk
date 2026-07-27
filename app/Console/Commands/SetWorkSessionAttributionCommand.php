<?php

namespace App\Console\Commands;

use App\Enums\WorkSessionOrigin;
use App\Models\WorkSession;
use App\Services\Operations\AttendanceRegisterService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Safely mark WorkSession attribution without deleting rows.
 *
 * Production example (Sumit ghost session 1391):
 *   php artisan work-sessions:set-attribution --id=1391 --origin=assignment --attributable=0
 *   php artisan attendance:reconcile-days --user=12 --from=2026-07-27 --to=2026-07-27
 */
class SetWorkSessionAttributionCommand extends Command
{
    protected $signature = 'work-sessions:set-attribution
                            {--id= : Single work session id}
                            {--ids= : Comma-separated work session ids}
                            {--origin= : Optional WorkSessionOrigin value (login|browser|system|assignment|migration)}
                            {--attributable= : 1/0 or true/false}
                            {--reconcile : Refresh attendance for affected user/dates after update}
                            {--dry-run : Show planned changes without writing}';

    protected $description = 'Mark WorkSession origin/is_attributable without deleting historical rows';

    public function handle(AttendanceRegisterService $attendanceRegister): int
    {
        $ids = $this->resolveIds();

        if ($ids === []) {
            $this->error('Provide --id= or --ids=.');

            return self::FAILURE;
        }

        $originOption = $this->option('origin');
        $origin = null;

        if ($originOption !== null && $originOption !== '') {
            $origin = WorkSessionOrigin::tryFrom((string) $originOption);

            if ($origin === null) {
                $this->error('Invalid --origin. Use: login, browser, system, assignment, migration.');

                return self::FAILURE;
            }
        }

        $attributableOption = $this->option('attributable');
        $attributable = null;

        if ($attributableOption !== null && $attributableOption !== '') {
            $attributable = filter_var($attributableOption, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($attributable === null) {
                $this->error('Invalid --attributable. Use 1/0 or true/false.');

                return self::FAILURE;
            }
        }

        if ($origin === null && $attributable === null) {
            $this->error('Provide at least one of --origin or --attributable.');

            return self::FAILURE;
        }

        $sessions = WorkSession::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            $this->error('No work sessions found for the given id(s).');

            return self::FAILURE;
        }

        if ($sessions->count() !== count($ids)) {
            $found = $sessions->pluck('id')->all();
            $missing = array_values(array_diff($ids, $found));
            $this->warn('Missing session id(s): '.implode(', ', $missing));
        }

        $dryRun = (bool) $this->option('dry-run');
        $affected = [];

        foreach ($sessions as $session) {
            $nextOrigin = $origin?->value ?? $session->origin?->value ?? WorkSessionOrigin::Migration->value;
            $nextAttributable = $attributable ?? (bool) $session->is_attributable;

            $this->line(sprintf(
                'session=%d user=%d date=%s origin %s→%s attributable %s→%s',
                $session->id,
                $session->user_id,
                $session->work_date?->toDateString() ?? 'n/a',
                $session->origin?->value ?? 'null',
                $nextOrigin,
                $session->is_attributable ? '1' : '0',
                $nextAttributable ? '1' : '0',
            ));

            if (! $dryRun) {
                $updates = [];

                if ($origin !== null) {
                    $updates['origin'] = $origin;
                }

                if ($attributable !== null) {
                    $updates['is_attributable'] = $attributable;
                }

                $session->fill($updates)->save();
            }

            $affected[] = [
                'user_id' => (int) $session->user_id,
                'work_date' => $session->work_date?->toDateString(),
            ];
        }

        $this->info(($dryRun ? '[dry-run] ' : '').'Processed '.$sessions->count().' session(s).');

        if ($dryRun || ! (bool) $this->option('reconcile')) {
            if (! $dryRun && ! (bool) $this->option('reconcile')) {
                $this->comment('Attendance not refreshed. Run attendance:reconcile-days for affected user/dates, or re-run with --reconcile.');
            }

            return self::SUCCESS;
        }

        $reconciled = 0;

        foreach (collect($affected)->unique(fn (array $row): string => $row['user_id'].'|'.$row['work_date']) as $row) {
            if ($row['work_date'] === null) {
                continue;
            }

            $user = \App\Models\User::query()->find($row['user_id']);

            if ($user === null) {
                continue;
            }

            $date = Carbon::parse($row['work_date'])->startOfDay();
            $attendanceRegister->refreshDay(
                user: $user,
                workDate: $date,
                referenceAt: $date->isSameDay(now()) ? now() : $date->copy()->endOfDay(),
                allowPreShiftSkip: false,
            );
            $reconciled++;
        }

        $this->info("Reconciled {$reconciled} attendance day row(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveIds(): array
    {
        $ids = [];

        if ($this->option('id') !== null && $this->option('id') !== '') {
            $ids[] = (int) $this->option('id');
        }

        if ($this->option('ids') !== null && $this->option('ids') !== '') {
            foreach (explode(',', (string) $this->option('ids')) as $part) {
                $part = trim($part);

                if ($part === '') {
                    continue;
                }

                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }
}
