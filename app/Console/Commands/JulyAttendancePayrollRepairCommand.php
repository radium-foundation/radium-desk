<?php

namespace App\Console\Commands;

use App\Services\Operations\JulyAttendancePayrollRepairService;
use Illuminate\Console\Command;

/**
 * One-shot July 2026 payroll attendance repair.
 * Does not change AttendanceDayCalculator, schedules, or August+.
 */
class JulyAttendancePayrollRepairCommand extends Command
{
    protected $signature = 'workforce:july-attendance-payroll-repair
                            {--dry-run : Show planned repairs without writing}
                            {--force : Execute without confirmation}';

    protected $description = 'Normalize July 2026 attendance for payroll migration (Holiday/Leave/Extra/WO/Present)';

    public function handle(JulyAttendancePayrollRepairService $repairService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $from = JulyAttendancePayrollRepairService::WINDOW_FROM;
        $to = JulyAttendancePayrollRepairService::WINDOW_TO;

        if (! $dryRun && ! $force && ! $this->confirm(
            sprintf('Repair July attendance payroll for %s → %s on this database?', $from, $to),
            false,
        )) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $result = $repairService->repair($dryRun);
        $counts = $result['counts'];

        if ($dryRun) {
            $this->warn('DRY RUN — no writes.');
            $this->newLine();
        }

        $this->line($dryRun
            ? 'July 2026 Attendance Payroll Repair (dry-run)'
            : 'July 2026 Attendance Payroll Repair Complete');
        $this->newLine();
        $this->line('Window:');
        $this->line(sprintf('%s → %s', $from, $to));
        $this->newLine();
        $this->line('Employees processed:');
        $this->line((string) $result['users']);
        $this->newLine();
        $this->line('Days repaired:');
        $this->line(sprintf('Present: %d', $counts['present']));
        $this->line(sprintf('Weekly Off: %d', $counts['weekly_off']));
        $this->line(sprintf('Leave preserved: %d', $counts['leave']));
        $this->line(sprintf('Half Day preserved: %d', $counts['half_day']));
        $this->line(sprintf('Extra preserved: %d', $counts['extra']));
        $this->line(sprintf('Holiday preserved: %d', $counts['holiday']));
        $this->newLine();
        $this->line('No WorkSessions were created.');
        $this->line('No August data was modified.');
        $this->newLine();
        $this->comment(sprintf(
            'Detail — changed: %d · unchanged: %d',
            $result['changed'],
            $result['unchanged'],
        ));

        return self::SUCCESS;
    }
}
