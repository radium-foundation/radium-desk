<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use App\Services\Workforce\Recognition\WorkRecognitionReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RecognitionScanCommand extends Command
{
    protected $signature = 'workforce:recognition-scan
                            {--month= : Target month YYYY-MM (default: current month)}
                            {--dry-run : Show candidates without writing}';

    protected $description = 'Scan Weekly Off / Holiday activity and create Work Recognition pending reviews';

    public function handle(
        WorkRecognitionReviewService $reviewService,
        OperationsRoleService $roleService,
    ): int {
        if (! $reviewService->enabled() && ! $this->option('dry-run')) {
            $this->warn('Work Recognition is disabled (workforce_recognition.enabled=false). Use --dry-run or enable the flag.');

            return self::SUCCESS;
        }

        $monthOption = $this->option('month');
        $month = is_string($monthOption) && preg_match('/^\d{4}-\d{2}$/', $monthOption) === 1
            ? Carbon::createFromFormat('Y-m', $monthOption)->startOfMonth()
            : now()->copy()->startOfMonth();

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $dryRun = (bool) $this->option('dry-run');

        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $roleService->isAttendanceTracked($user));

        $created = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                if ($dryRun || ! $reviewService->enabled()) {
                    $candidate = app(\App\Services\Workforce\Recognition\RecognitionCandidateDetector::class)
                        ->detect($user, $cursor);
                    if ($candidate !== null) {
                        $this->line(sprintf(
                            '[candidate] %s %s %s',
                            $user->name,
                            $candidate->workDate->toDateString(),
                            $candidate->dayContext->value,
                        ));
                        $created++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $before = \App\Models\WorkRecognitionReview::query()
                        ->where('user_id', $user->id)
                        ->whereDate('work_date', $cursor->toDateString())
                        ->first();

                    $review = $reviewService->ensurePendingFor($user, $cursor);
                    if ($review !== null && ($before === null || $before->isPending())) {
                        $created++;
                        $this->line(sprintf(
                            '[review] #%d %s %s %s → %s',
                            $review->id,
                            $user->name,
                            $review->work_date->toDateString(),
                            $review->day_context->value,
                            $review->ira_recommendation->value,
                        ));
                    } else {
                        $skipped++;
                    }
                }

                $cursor->addDay();
            }
        }

        $this->info(($dryRun ? 'Dry-run candidates: ' : 'Reviews touched: ').$created.' (skipped days: '.$skipped.')');

        return self::SUCCESS;
    }
}
