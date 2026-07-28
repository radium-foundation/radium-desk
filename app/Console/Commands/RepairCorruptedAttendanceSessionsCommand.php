<?php

namespace App\Console\Commands;

use App\Models\WorkSession;
use App\Services\Operations\AttendanceRegisterService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One-time historical cleanup for inflated Working Hours.
 *
 * Caps closed sessions where active_duration_seconds exceeded wall-clock
 * session_duration_seconds (premature endOfDay tick bug). Does not belong
 * in normal attendance processing.
 *
 *   php artisan attendance:repair-corrupted-sessions
 */
class RepairCorruptedAttendanceSessionsCommand extends Command
{
    protected $signature = 'attendance:repair-corrupted-sessions';

    protected $description = 'One-time repair: clamp inflated active_duration_seconds on closed work sessions, then reconcile affected attendance days';

    public function handle(AttendanceRegisterService $attendanceRegister): int
    {
        /** @var Collection<int, WorkSession> $sessions */
        $sessions = WorkSession::query()
            ->with('user:id,name')
            ->whereNotNull('logout_at')
            ->whereColumn('active_duration_seconds', '>', 'session_duration_seconds')
            ->orderBy('work_date')
            ->orderBy('id')
            ->get();

        $scanned = $sessions->count();
        $repaired = 0;
        $affectedDates = [];

        foreach ($sessions as $session) {
            $oldActive = (int) $session->active_duration_seconds;
            $sessionDuration = (int) $session->session_duration_seconds;
            $workDate = $session->work_date?->toDateString() ?? 'n/a';

            $this->line(sprintf(
                'repair session=%d user=%s (%d) date=%s active=%d→%d session_duration=%d',
                $session->id,
                $session->user?->name ?? 'unknown',
                (int) $session->user_id,
                $workDate,
                $oldActive,
                $sessionDuration,
                $sessionDuration,
            ));

            WorkSession::query()
                ->whereKey($session->id)
                ->update([
                    'active_duration_seconds' => $sessionDuration,
                ]);

            $repaired++;

            if ($session->work_date !== null) {
                $affectedDates[] = $session->work_date->toDateString();
            }
        }

        $this->newLine();
        $this->info("Sessions scanned: {$scanned}");
        $this->info("Sessions repaired: {$repaired}");

        if ($repaired === 0) {
            $this->info('Date range reconciled: none');

            return self::SUCCESS;
        }

        $uniqueDates = collect($affectedDates)->unique()->sort()->values();
        $from = Carbon::parse((string) $uniqueDates->first())->startOfDay();
        $to = Carbon::parse((string) $uniqueDates->last())->startOfDay();

        $reconciled = $attendanceRegister->reconcileRange(
            startDate: $from,
            endDate: $to,
        );

        $this->info(sprintf(
            'Date range reconciled: %s → %s (%d attendance day row(s))',
            $from->toDateString(),
            $to->toDateString(),
            $reconciled,
        ));

        return self::SUCCESS;
    }
}
