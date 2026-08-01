<?php

namespace App\Console\Commands;

use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Operations\LeaveRequestService;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * One-shot July 2026 leave reconciliation via LeaveRequest submit/approve/reject.
 * Does not touch OT, schedule backfill, or attendance rows directly.
 */
class JulyLeaveReconcileCommand extends Command
{
    protected $signature = 'workforce:july-leave-reconcile
                            {--dry-run : Show planned actions without writing}
                            {--force : Execute without confirmation}';

    protected $description = 'Reconcile July 2026 leave/half-day via LeaveRequest workflow (payroll)';

    /** @var list<array{action: string, detail: string}> */
    private array $log = [];

    public function handle(LeaveRequestService $leaveService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force && ! $this->confirm('Execute July leave reconciliation on this database?', false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        // Allow creating verified July dates after month-end (command-scoped only).
        config(['workforce_calendar.retroactive_leave_days' => 60]);
        Notification::fake();

        $reviewerSuper = User::query()->where('email', 'info@radiumbox.com')->first()
            ?? User::role(RolePermissionSeeder::ROLE_SUPERADMIN)->where('is_active', true)->orderBy('id')->first();
        $reviewerOps = User::query()->where('email', 'shipra@radiumbox.com')->first()
            ?? User::role(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN)->where('is_active', true)->orderBy('id')->first();

        if ($reviewerSuper === null || $reviewerOps === null) {
            $this->error('Need an active superadmin and operations_admin reviewer.');

            return self::FAILURE;
        }

        $spec = $this->verifiedSpec();
        $users = $this->resolveUsers(array_keys($spec));

        try {
            $this->resolveShipraRange($leaveService, $users['Shipra'], $reviewerSuper, $dryRun);
            $this->approveExactPending($leaveService, $users, $reviewerSuper, $reviewerOps, $dryRun);
            $this->resolveAbhinavDuplicates($leaveService, $users['Abhinav'], $reviewerOps, $dryRun);
            $this->createAndApproveMissing($leaveService, $users, $spec, $reviewerSuper, $reviewerOps, $dryRun);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($dryRun ? 'DRY RUN — no writes.' : 'Reconciliation actions applied.');
        foreach ($this->log as $row) {
            $this->line(sprintf('[%s] %s', $row['action'], $row['detail']));
        }

        if (! $dryRun) {
            $this->newLine();
            $this->printVerification($users, $spec);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{leave: list<int>, half: list<int>}>
     */
    private function verifiedSpec(): array
    {
        return [
            'Dileep' => ['leave' => [1, 2, 10, 11, 12, 13, 26, 27, 28], 'half' => []],
            'Sumit' => ['leave' => [2, 19, 20, 23, 29], 'half' => [21]],
            'Sushant' => ['leave' => [2, 18], 'half' => [27]],
            'Shubhanshi' => ['leave' => [7, 16, 17, 23], 'half' => [6]],
            'Abhinav' => ['leave' => [7, 14], 'half' => [27]],
            'Shashank' => ['leave' => [2, 19, 20, 24, 28, 30], 'half' => []],
            'Avinash' => ['leave' => [14], 'half' => []],
            'Gaurav' => ['leave' => [], 'half' => [15, 29]],
            'Riya' => ['leave' => [3], 'half' => []],
            'Shipra' => ['leave' => [13], 'half' => []],
        ];
    }

    /**
     * @param  list<string>  $needles
     * @return array<string, User>
     */
    private function resolveUsers(array $needles): array
    {
        $out = [];
        foreach ($needles as $needle) {
            $user = User::query()->where('name', 'like', '%'.$needle.'%')->orderBy('id')->first();
            if ($user === null) {
                throw new \RuntimeException("User not found for {$needle}");
            }
            $out[$needle] = $user;
        }

        return $out;
    }

    private function resolveShipraRange(
        LeaveRequestService $leaveService,
        User $shipra,
        User $reviewerSuper,
        bool $dryRun,
    ): void {
        $pending = LeaveRequest::query()
            ->where('user_id', $shipra->id)
            ->where('status', LeaveRequestStatus::Pending)
            ->whereDate('start_date', '2026-07-13')
            ->whereDate('end_date', '2026-07-14')
            ->get();

        foreach ($pending as $lr) {
            $this->record('reject', "Shipra #{$lr->id} {$lr->start_date->toDateString()}→{$lr->end_date->toDateString()} (range outside SoT)");
            if (! $dryRun) {
                $leaveService->reject($lr, $reviewerSuper, 'July payroll: SoT Leave is 13 only; rejecting 13–14 range to recreate.');
            }
        }
    }

    /**
     * @param  array<string, User>  $users
     */
    private function approveExactPending(
        LeaveRequestService $leaveService,
        array $users,
        User $reviewerSuper,
        User $reviewerOps,
        bool $dryRun,
    ): void {
        // Avinash # exact Jul 14 full day pending
        $avinashPending = LeaveRequest::query()
            ->where('user_id', $users['Avinash']->id)
            ->where('status', LeaveRequestStatus::Pending)
            ->whereDate('start_date', '2026-07-14')
            ->whereDate('end_date', '2026-07-14')
            ->where('duration', LeaveDuration::FullDay)
            ->orderBy('id')
            ->get();

        foreach ($avinashPending as $index => $lr) {
            if ($index === 0) {
                $this->record('approve', "Avinash #{$lr->id} Jul 14 full_day");
                if (! $dryRun) {
                    $leaveService->approve($lr, $reviewerSuper, 'July payroll reconciliation — verified Leave 14.');
                }
            } else {
                $this->record('reject', "Avinash duplicate #{$lr->id} Jul 14");
                if (! $dryRun) {
                    $leaveService->reject($lr, $reviewerSuper, 'July payroll: duplicate pending rejected.');
                }
            }
        }

        // Dileep Jul 26 pending
        $dileepPending = LeaveRequest::query()
            ->where('user_id', $users['Dileep']->id)
            ->where('status', LeaveRequestStatus::Pending)
            ->whereDate('start_date', '2026-07-26')
            ->whereDate('end_date', '2026-07-26')
            ->orderBy('id')
            ->get();

        foreach ($dileepPending as $index => $lr) {
            if ($index === 0) {
                $this->record('approve', "Dileep #{$lr->id} Jul 26 full_day");
                if (! $dryRun) {
                    $leaveService->approve($lr, $reviewerSuper, 'July payroll reconciliation — verified Leave 26.');
                }
            } else {
                $this->record('reject', "Dileep duplicate #{$lr->id} Jul 26");
                if (! $dryRun) {
                    $leaveService->reject($lr, $reviewerSuper, 'July payroll: duplicate pending rejected.');
                }
            }
        }
    }

    private function resolveAbhinavDuplicates(
        LeaveRequestService $leaveService,
        User $abhinav,
        User $reviewerOps,
        bool $dryRun,
    ): void {
        $pending = LeaveRequest::query()
            ->where('user_id', $abhinav->id)
            ->where('status', LeaveRequestStatus::Pending)
            ->whereDate('start_date', '2026-07-14')
            ->whereDate('end_date', '2026-07-14')
            ->orderBy('id')
            ->get();

        foreach ($pending as $index => $lr) {
            if ($index === 0) {
                // SoT: Full Day Leave on 14 (pending rows already full_day).
                $this->record('approve', "Abhinav #{$lr->id} Jul 14 full_day (keep one)");
                if (! $dryRun) {
                    $leaveService->approve($lr, $reviewerOps, 'July payroll reconciliation — verified Leave 14.');
                }
            } else {
                $this->record('reject', "Abhinav duplicate #{$lr->id} Jul 14");
                if (! $dryRun) {
                    $leaveService->reject($lr, $reviewerOps, 'July payroll: duplicate pending rejected.');
                }
            }
        }
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array{leave: list<int>, half: list<int>}>  $spec
     */
    private function createAndApproveMissing(
        LeaveRequestService $leaveService,
        array $users,
        array $spec,
        User $reviewerSuper,
        User $reviewerOps,
        bool $dryRun,
    ): void {
        foreach ($spec as $needle => $days) {
            $user = $users[$needle];
            $reviewer = $this->reviewerFor($user, $reviewerSuper, $reviewerOps);

            foreach ($days['leave'] as $day) {
                $date = sprintf('2026-07-%02d', $day);
                if ($this->hasApprovedCovering($user, $date, LeaveDuration::FullDay)) {
                    continue;
                }

                // Pending that already covers this exact single day as full_day should have been approved above.
                $blockingPending = LeaveRequest::query()
                    ->where('user_id', $user->id)
                    ->where('status', LeaveRequestStatus::Pending)
                    ->whereDate('start_date', '<=', $date)
                    ->whereDate('end_date', '>=', $date)
                    ->exists();

                if ($blockingPending) {
                    $this->record('blocker', "{$needle} {$date}: pending still blocks create — resolve manually");

                    continue;
                }

                $this->record('create+approve', "{$needle} Full Day {$date}");
                if ($dryRun) {
                    continue;
                }

                $created = $leaveService->submit($user, [
                    'start_date' => $date,
                    'end_date' => $date,
                    'duration' => LeaveDuration::FullDay->value,
                    'reason' => 'July payroll reconciliation — verified full-day leave.',
                ]);
                $leaveService->approve($created, $reviewer, 'July payroll reconciliation — verified Leave '.$day.'.');
                $this->record('created', "{$needle} #{$created->id} Full Day {$date}");
                $this->record('approved', "{$needle} #{$created->id} Full Day {$date}");
            }

            foreach ($days['half'] as $day) {
                $date = sprintf('2026-07-%02d', $day);
                if ($this->hasApprovedCovering($user, $date, LeaveDuration::HalfDay)) {
                    continue;
                }

                $blockingPending = LeaveRequest::query()
                    ->where('user_id', $user->id)
                    ->where('status', LeaveRequestStatus::Pending)
                    ->whereDate('start_date', '<=', $date)
                    ->whereDate('end_date', '>=', $date)
                    ->exists();

                if ($blockingPending) {
                    $this->record('blocker', "{$needle} {$date} half: pending still blocks create");

                    continue;
                }

                $this->record('create+approve', "{$needle} Half Day {$date}");
                if ($dryRun) {
                    continue;
                }

                $created = $leaveService->submit($user, [
                    'start_date' => $date,
                    'end_date' => $date,
                    'duration' => LeaveDuration::HalfDay->value,
                    'reason' => 'July payroll reconciliation — verified half-day leave.',
                ]);
                $leaveService->approve($created, $reviewer, 'July payroll reconciliation — verified Half Day '.$day.'.');
                $this->record('created', "{$needle} #{$created->id} Half Day {$date}");
                $this->record('approved', "{$needle} #{$created->id} Half Day {$date}");
            }
        }
    }

    private function hasApprovedCovering(User $user, string $date, LeaveDuration $duration): bool
    {
        return LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequestStatus::Approved)
            ->where('duration', $duration)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }

    private function reviewerFor(User $requester, User $super, User $ops): User
    {
        if ($requester->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ])) {
            return $super;
        }

        return $ops;
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array{leave: list<int>, half: list<int>}>  $spec
     */
    private function printVerification(array $users, array $spec): void
    {
        $month = Carbon::parse('2026-07-01')->startOfMonth();
        $at = Carbon::parse('2026-07-31 23:59:59', 'Asia/Kolkata');
        $matrix = app(MonthlyAttendanceMatrixService::class)->build($month, $at);
        $byId = collect($matrix->members)->keyBy('userId');

        $this->info('Verification vs matrix:');
        $remaining = 0;

        foreach ($spec as $needle => $days) {
            $user = $users[$needle];
            $member = $byId->get($user->id);
            if ($member === null) {
                $this->error("{$needle}: not in matrix");
                $remaining++;

                continue;
            }

            $cells = [];
            foreach ($member->cells as $date => $cell) {
                $cells[(int) Carbon::parse($date)->day] = $cell->kind->value;
            }

            foreach ($days['leave'] as $day) {
                $got = $cells[$day] ?? 'missing';
                $ok = $got === 'leave';
                if (! $ok) {
                    $remaining++;
                }
                $this->line(sprintf('  %s Leave %02d: %s%s', $needle, $day, $got, $ok ? '' : ' ✗'));
            }
            foreach ($days['half'] as $day) {
                $got = $cells[$day] ?? 'missing';
                $ok = $got === 'half_day';
                if (! $ok) {
                    $remaining++;
                }
                $this->line(sprintf('  %s Half %02d: %s%s', $needle, $day, $got, $ok ? '' : ' ✗'));
            }
        }

        $this->newLine();
        $this->info($remaining === 0
            ? 'All verified Leave/Half Day dates match the matrix.'
            : "Remaining mismatches: {$remaining}");
    }

    private function record(string $action, string $detail): void
    {
        $this->log[] = compact('action', 'detail');
    }
}
