<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Operations\AttendanceRegisterService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ReconcileAttendanceDaysCommand extends Command
{
    protected $signature = 'attendance:reconcile-days
                            {--from= : Inclusive start date (Y-m-d)}
                            {--to= : Inclusive end date (Y-m-d)}
                            {--user= : Limit reconciliation to a single user id}';

    protected $description = 'Rebuild workforce attendance register rows from work sessions';

    public function handle(AttendanceRegisterService $attendanceRegister): int
    {
        $startDate = $this->option('from') !== null
            ? Carbon::parse((string) $this->option('from'))->startOfDay()
            : null;
        $endDate = $this->option('to') !== null
            ? Carbon::parse((string) $this->option('to'))->startOfDay()
            : null;

        $users = null;

        if ($this->option('user') !== null) {
            $user = User::query()->find((int) $this->option('user'));

            if ($user === null) {
                $this->error('User not found.');

                return self::FAILURE;
            }

            $users = collect([$user]);
        }

        $reconciled = $attendanceRegister->reconcileRange(
            startDate: $startDate,
            endDate: $endDate,
            users: $users,
        );

        $this->info("Reconciled {$reconciled} attendance day row(s).");

        return self::SUCCESS;
    }
}
