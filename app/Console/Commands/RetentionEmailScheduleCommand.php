<?php

namespace App\Console\Commands;

use App\Data\Retention\RetentionEmailScheduleResult;
use App\Services\Retention\RetentionEmailScheduleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('database:retention-email-schedule
    {--dry-run-only : Run daily retention dry-runs and write audit artifacts}
    {--weekly-execute : Run weekly preflight, safety gates, and approved deletions}')]
#[Description('Orchestrate recurring inbound email retention dry-runs and weekly approved deletions')]
class RetentionEmailScheduleCommand extends Command
{
    public const SCHEDULE_MUTEX = 'database:retention-email-schedule';

    public function __construct(
        private readonly RetentionEmailScheduleService $scheduleService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRunOnly = (bool) $this->option('dry-run-only');
        $weeklyExecute = (bool) $this->option('weekly-execute');

        if ($dryRunOnly === $weeklyExecute) {
            $this->error('Pass exactly one of --dry-run-only or --weekly-execute.');

            return self::FAILURE;
        }

        if (! (bool) config('retention.email_retention.scheduler_enabled', false)) {
            $this->error('Email retention scheduler is disabled (retention.email_retention.scheduler_enabled=false).');

            return self::FAILURE;
        }

        try {
            $result = $weeklyExecute
                ? $this->scheduleService->runWeeklyExecute()
                : $this->scheduleService->runDailyDryRun();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            Log::error('database.retention_email_schedule.failed', [
                'mode' => $weeklyExecute ? RetentionEmailScheduleService::MODE_WEEKLY_EXECUTE : RetentionEmailScheduleService::MODE_DAILY_DRY_RUN,
                'message' => $exception->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->renderResult($result);

        Log::info('database.retention_email_schedule.completed', [
            'mode' => $result->mode,
            'success' => $result->success,
            'aborted' => $result->aborted,
            'unknown_customer_candidates' => $result->unknownCustomerCandidates,
            'noise_candidates' => $result->noiseCandidates,
            'unknown_customer_deleted' => $result->unknownCustomerDeleted,
            'noise_deleted' => $result->noiseDeleted,
            'audit_log_path' => $result->auditLogPath,
            'anomalies' => $result->anomalies,
        ]);

        return $result->success ? self::SUCCESS : self::FAILURE;
    }

    private function renderResult(RetentionEmailScheduleResult $result): void
    {
        $this->newLine();
        $this->info(sprintf('Mode: %s', $result->mode));
        $this->line(sprintf('unknown_customer threshold: %d days', $result->unknownCustomerDays));
        $this->line(sprintf('approved noise threshold: %d days', $result->ignoredEmailDays));
        $this->newLine();

        $this->table(
            ['Population', 'Candidates', 'Deleted', 'Post-dry-run'],
            [
                ['unknown_customer', number_format($result->unknownCustomerCandidates), number_format($result->unknownCustomerDeleted), number_format($result->postUnknownCustomerCandidates)],
                ['approved noise', number_format($result->noiseCandidates), number_format($result->noiseDeleted), number_format($result->postNoiseCandidates)],
                ['needs_review backlog', number_format($result->needsReviewBacklog), '—', '—'],
                ['order-linked count', number_format($result->orderLinkedCount), '—', '—'],
            ],
        );

        if ($result->unknownCustomerManifestPath !== null) {
            $this->line(sprintf('unknown_customer manifest: %s', $result->unknownCustomerManifestPath));
            $this->line(sprintf('unknown_customer manifest SHA256: %s', $result->unknownCustomerManifestSha256 ?? '—'));
        }

        if ($result->noiseManifestPath !== null) {
            $this->line(sprintf('approved noise manifest: %s', $result->noiseManifestPath));
            $this->line(sprintf('approved noise manifest SHA256: %s', $result->noiseManifestSha256 ?? '—'));
        }

        if ($result->auditLogPath !== null) {
            $this->line(sprintf('Audit log: %s', $result->auditLogPath));
        }

        if ($result->anomalies !== []) {
            $this->newLine();
            $this->warn('Anomalies:');
            foreach ($result->anomalies as $anomaly) {
                $this->line('- '.$anomaly);
            }
        }

        if ($result->aborted) {
            $this->newLine();
            $this->error($result->abortReason ?? 'Email retention schedule aborted.');
        } elseif ($result->mode === RetentionEmailScheduleService::MODE_DAILY_DRY_RUN) {
            $this->newLine();
            $this->line('DRY-RUN ONLY — NO ROWS DELETED');
        }
    }
}
