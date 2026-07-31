<?php

namespace App\Console\Commands;

use App\Models\WorkSession;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\PresenceEngineService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One-time historical cleanup for inflated Working Hours and Overtime.
 *
 * - Caps closed sessions where active_duration_seconds exceeded wall-clock
 *   session_duration_seconds (premature endOfDay tick bug).
 * - Recalculates overtime_seconds from login/logout/schedule using current
 *   PresenceEngineService OT rules (overnight-correct expected end + session
 *   overlap past shift end, login-day end-of-day cap).
 *
 * Does not belong in normal attendance processing.
 *
 *   php artisan attendance:repair-corrupted-sessions
 */
class RepairCorruptedAttendanceSessionsCommand extends Command
{
    protected $signature = 'attendance:repair-corrupted-sessions';

    protected $description = 'One-time repair: clamp inflated active_duration_seconds and recalculate overtime_seconds on closed work sessions, then reconcile affected attendance days';

    public function handle(
        AttendanceRegisterService $attendanceRegister,
        PresenceEngineService $presenceEngine,
    ): int {
        $affectedDates = [];
        $activeRepaired = $this->repairInflatedActiveDuration($affectedDates);
        $overtimeRepaired = $this->repairCorruptedOvertime($presenceEngine, $affectedDates);

        $repaired = $activeRepaired + $overtimeRepaired;

        $this->newLine();
        $this->info("Active duration sessions repaired: {$activeRepaired}");
        $this->info("Overtime sessions repaired: {$overtimeRepaired}");
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

    /**
     * @param  list<string>  $affectedDates
     */
    private function repairInflatedActiveDuration(array &$affectedDates): int
    {
        /** @var Collection<int, WorkSession> $sessions */
        $sessions = WorkSession::query()
            ->with('user:id,name')
            ->whereNotNull('logout_at')
            ->whereColumn('active_duration_seconds', '>', 'session_duration_seconds')
            ->orderBy('work_date')
            ->orderBy('id')
            ->get();

        $this->info('Active duration candidates scanned: '.$sessions->count());

        $repaired = 0;

        foreach ($sessions as $session) {
            $oldActive = (int) $session->active_duration_seconds;
            $sessionDuration = (int) $session->session_duration_seconds;
            $workDate = $session->work_date?->toDateString() ?? 'n/a';

            $this->line(sprintf(
                'repair active session=%d user=%s (%d) date=%s active=%d→%d session_duration=%d',
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

        return $repaired;
    }

    /**
     * @param  list<string>  $affectedDates
     */
    private function repairCorruptedOvertime(
        PresenceEngineService $presenceEngine,
        array &$affectedDates,
    ): int {
        $repaired = 0;
        $scanned = 0;

        WorkSession::query()
            ->with(['user:id,name', 'user.workSchedule'])
            ->whereNotNull('logout_at')
            ->whereNotNull('login_at')
            ->whereNotNull('work_date')
            ->orderBy('work_date')
            ->orderBy('id')
            ->chunkById(200, function (Collection $sessions) use (
                $presenceEngine,
                &$affectedDates,
                &$repaired,
                &$scanned,
            ): void {
                foreach ($sessions as $session) {
                    $scanned++;

                    $oldOvertime = (int) $session->overtime_seconds;
                    $correctOvertime = $presenceEngine->recalculateOvertimeSeconds($session);

                    if ($oldOvertime === $correctOvertime) {
                        continue;
                    }

                    $workDate = $session->work_date?->toDateString() ?? 'n/a';

                    $this->line(sprintf(
                        'repair overtime session=%d user=%s (%d) date=%s overtime=%d→%d',
                        $session->id,
                        $session->user?->name ?? 'unknown',
                        (int) $session->user_id,
                        $workDate,
                        $oldOvertime,
                        $correctOvertime,
                    ));

                    WorkSession::query()
                        ->whereKey($session->id)
                        ->update([
                            'overtime_seconds' => $correctOvertime,
                        ]);

                    $session->overtime_seconds = $correctOvertime;
                    $repaired++;

                    if ($session->work_date !== null) {
                        $affectedDates[] = $session->work_date->toDateString();
                    }
                }
            });

        $this->info("Overtime candidates scanned: {$scanned}");

        return $repaired;
    }
}
