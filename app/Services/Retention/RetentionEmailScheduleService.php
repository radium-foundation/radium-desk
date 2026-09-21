<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionEmailScheduleResult;
use App\Data\Retention\RetentionIgnoredEmailPruneSummary;
use App\Data\Retention\RetentionUnknownCustomerPruneSummary;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RetentionEmailScheduleService
{
    public const MODE_DAILY_DRY_RUN = 'daily_dry_run';

    public const MODE_WEEKLY_EXECUTE = 'weekly_execute';

    private mixed $lockHandle = null;

    public function __construct(
        private readonly RetentionUnknownCustomerPruneService $unknownCustomerPruneService,
        private readonly RetentionIgnoredEmailPruneService $ignoredEmailPruneService,
    ) {}

    public function runDailyDryRun(?Carbon $at = null): RetentionEmailScheduleResult
    {
        return $this->run(mode: self::MODE_DAILY_DRY_RUN, execute: false, at: $at);
    }

    public function runWeeklyExecute(?Carbon $at = null): RetentionEmailScheduleResult
    {
        return $this->run(mode: self::MODE_WEEKLY_EXECUTE, execute: true, at: $at);
    }

    private function run(string $mode, bool $execute, ?Carbon $at = null): RetentionEmailScheduleResult
    {
        $startedAt = ($at ?? now())->copy();
        $timestamp = $startedAt->utc()->format('Ymd\THis\Z');
        $manifestDirectory = $this->manifestDirectory();
        $anomalies = [];
        $abortReason = null;
        $aborted = false;

        $this->ensureManifestDirectory($manifestDirectory);
        $this->acquireProcessLock();

        try {
            $this->assertConfiguration($anomalies);

            if ($execute) {
                $this->assertRecoveryBackupAvailable($anomalies);
                $this->assertPreviousWeeklyExecutionSucceeded($anomalies);
            }

            $unknownManifest = $manifestDirectory.'/unknown-customer-schedule-'.$timestamp.'.txt';
            $noiseManifest = $manifestDirectory.'/noise-schedule-'.$timestamp.'.txt';

            $unknownSummary = $this->unknownCustomerPruneService->prune(
                dryRun: true,
                manifestPath: $unknownManifest,
            );
            $noiseSummary = $this->ignoredEmailPruneService->prune(
                dryRun: true,
                manifestPath: $noiseManifest,
            );

            $unknownSafetyFailures = $unknownSummary->integrityFailureCount();
            $noiseSafetyFailures = $noiseSummary->integrityFailureCount();

            $this->collectSafetyAnomalies(
                unknownSummary: $unknownSummary,
                noiseSummary: $noiseSummary,
                anomalies: $anomalies,
            );

            if ($unknownSafetyFailures > 0 || $noiseSafetyFailures > 0) {
                $anomalies[] = sprintf(
                    'Safety gate failure (unknown_customer=%d, noise=%d).',
                    $unknownSafetyFailures,
                    $noiseSafetyFailures,
                );
            }

            $previousState = $this->readState();
            $this->collectGrowthAnomalies(
                unknownSummary: $unknownSummary,
                noiseSummary: $noiseSummary,
                previousState: $previousState,
                anomalies: $anomalies,
                abortOnGrowth: $execute,
            );

            $metrics = $this->collectMonitoringMetrics();

            $unknownDeleted = 0;
            $noiseDeleted = 0;
            $unknownBatches = 0;
            $noiseBatches = 0;
            $postUnknownCandidates = $unknownSummary->candidateCount;
            $postNoiseCandidates = $noiseSummary->candidateCount;

            if ($execute && $anomalies === []) {
                $unknownExecute = $this->unknownCustomerPruneService->prune(
                    dryRun: false,
                    manifestPath: $unknownManifest,
                );
                $noiseExecute = $this->ignoredEmailPruneService->prune(
                    dryRun: false,
                    manifestPath: $noiseManifest,
                );

                $unknownDeleted = $unknownExecute->deletedCount;
                $noiseDeleted = $noiseExecute->deletedCount;
                $unknownBatches = $unknownExecute->batchesProcessed;
                $noiseBatches = $noiseExecute->batchesProcessed;

                $postUnknownSummary = $this->unknownCustomerPruneService->prune(
                    dryRun: true,
                    manifestPath: null,
                );
                $postNoiseSummary = $this->ignoredEmailPruneService->prune(
                    dryRun: true,
                    manifestPath: null,
                );

                $postUnknownCandidates = $postUnknownSummary->candidateCount;
                $postNoiseCandidates = $postNoiseSummary->candidateCount;

                if ($unknownExecute->integrityFailureCount() > 0 || $noiseExecute->integrityFailureCount() > 0) {
                    $anomalies[] = 'Integrity failure detected during execute summaries.';
                }
            } elseif ($execute) {
                $aborted = true;
                $abortReason = implode(' ', $anomalies);
            }

            $finishedAt = now();
            $success = ! $aborted && $anomalies === [];

            $auditLogPath = $this->writeAuditLog(
                mode: $mode,
                timestamp: $timestamp,
                unknownSummary: $unknownSummary,
                noiseSummary: $noiseSummary,
                metrics: $metrics,
                unknownDeleted: $unknownDeleted,
                noiseDeleted: $noiseDeleted,
                unknownBatches: $unknownBatches,
                noiseBatches: $noiseBatches,
                postUnknownCandidates: $postUnknownCandidates,
                postNoiseCandidates: $postNoiseCandidates,
                anomalies: $anomalies,
                success: $success,
                aborted: $aborted,
                abortReason: $abortReason,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
            );

            $this->writeState(
                mode: $mode,
                success: $success,
                unknownSummary: $unknownSummary,
                noiseSummary: $noiseSummary,
                unknownDeleted: $unknownDeleted,
                noiseDeleted: $noiseDeleted,
                postUnknownCandidates: $postUnknownCandidates,
                postNoiseCandidates: $postNoiseCandidates,
                finishedAt: $finishedAt,
                anomalies: $anomalies,
                metrics: $metrics,
            );

            $this->pruneOldArtifacts($manifestDirectory);

            return new RetentionEmailScheduleResult(
                mode: $mode,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                success: $success,
                aborted: $aborted,
                abortReason: $abortReason,
                unknownCustomerDays: $unknownSummary->unknownCustomerDays,
                ignoredEmailDays: $noiseSummary->ignoredEmailDays,
                unknownCustomerCandidates: $unknownSummary->candidateCount,
                noiseCandidates: $noiseSummary->candidateCount,
                needsReviewBacklog: $metrics['needs_review'],
                orderLinkedCount: $metrics['order_linked'],
                unknownCustomerAgeBuckets: $unknownSummary->candidatesByAgeBucket,
                noiseCandidatesByIgnoreReason: $noiseSummary->candidatesByIgnoreReason,
                noiseAgeBuckets: $noiseSummary->candidatesByAgeBucket,
                unknownCustomerOldestReceivedAt: $unknownSummary->oldestCandidateReceivedAt,
                unknownCustomerNewestReceivedAt: $unknownSummary->newestCandidateReceivedAt,
                noiseOldestReceivedAt: $noiseSummary->oldestCandidateReceivedAt,
                noiseNewestReceivedAt: $noiseSummary->newestCandidateReceivedAt,
                unknownCustomerEstimatedBytes: $unknownSummary->estimatedPayloadBytes,
                noiseEstimatedBytes: $noiseSummary->estimatedPayloadBytes,
                unknownCustomerManifestPath: $unknownSummary->manifestPath,
                unknownCustomerManifestSha256: $unknownSummary->manifestSha256,
                noiseManifestPath: $noiseSummary->manifestPath,
                noiseManifestSha256: $this->hashFile($noiseSummary->manifestPath),
                unknownCustomerSafetyFailures: $unknownSafetyFailures,
                noiseSafetyFailures: $noiseSafetyFailures,
                unknownCustomerDeleted: $unknownDeleted,
                noiseDeleted: $noiseDeleted,
                unknownCustomerBatches: $unknownBatches,
                noiseBatches: $noiseBatches,
                postUnknownCustomerCandidates: $postUnknownCandidates,
                postNoiseCandidates: $postNoiseCandidates,
                auditLogPath: $auditLogPath,
                anomalies: $anomalies,
            );
        } finally {
            $this->releaseProcessLock();
        }
    }

    /**
     * @return list<string>
     */
    public function approvedNoiseIgnoreReasons(): array
    {
        $reasons = config('retention.approved_noise_ignore_reasons', []);

        return array_values(array_filter(
            is_array($reasons) ? $reasons : [],
            static fn (mixed $reason): bool => is_string($reason) && $reason !== '',
        ));
    }

    /**
     * @param  list<string>  $anomalies
     */
    private function assertConfiguration(array &$anomalies): void
    {
        $expectedUnknownDays = (int) config('retention.email_retention.expected_unknown_customer_days', 30);
        $expectedNoiseDays = (int) config('retention.email_retention.expected_ignored_email_days', 90);
        $actualUnknownDays = (int) config('retention.unknown_customer_days', 30);
        $actualNoiseDays = (int) config('retention.ignored_email_days', 90);

        if ($actualUnknownDays !== $expectedUnknownDays) {
            $anomalies[] = sprintf(
                'Unexpected unknown_customer retention threshold (%d != %d).',
                $actualUnknownDays,
                $expectedUnknownDays,
            );
        }

        if ($actualNoiseDays !== $expectedNoiseDays) {
            $anomalies[] = sprintf(
                'Unexpected noise retention threshold (%d != %d).',
                $actualNoiseDays,
                $expectedNoiseDays,
            );
        }

        $configuredAllowlist = config('retention.ignored_email.ignore_reasons', []);
        $approved = $this->approvedNoiseIgnoreReasons();

        if (! is_array($configuredAllowlist) || array_values($configuredAllowlist) !== $approved) {
            $anomalies[] = 'Unexpected approved-noise allowlist configuration.';
        }

        if (in_array('unknown_customer', $approved, true)
            || in_array('unknown_customer', is_array($configuredAllowlist) ? $configuredAllowlist : [], true)) {
            $anomalies[] = 'unknown_customer must not appear in the noise allowlist.';
        }
    }

    /**
     * @param  list<string>  $anomalies
     */
    private function assertRecoveryBackupAvailable(array &$anomalies): void
    {
        $runsRoot = (string) config('retention.email_retention.recovery_runs_root', '/var/backups/radium-desk/runs');

        if (! is_dir($runsRoot)) {
            $anomalies[] = 'Recovery backup runs directory is unavailable.';

            return;
        }

        $latest = null;

        foreach (glob(rtrim($runsRoot, '/').'/*/database.sql.gz.gpg') ?: [] as $path) {
            if ($latest === null || filemtime($path) > filemtime($latest)) {
                $latest = $path;
            }
        }

        if ($latest === null || ! is_file($latest)) {
            $anomalies[] = 'No encrypted database backup artifact found for recovery.';

            return;
        }

        $maxAgeDays = max(1, (int) config('retention.email_retention.recovery_backup_max_age_days', 14));

        if (filemtime($latest) < now()->subDays($maxAgeDays)->getTimestamp()) {
            $anomalies[] = sprintf('Latest recovery backup is older than %d days.', $maxAgeDays);
        }
    }

    /**
     * @param  list<string>  $anomalies
     */
    private function assertPreviousWeeklyExecutionSucceeded(array &$anomalies): void
    {
        $state = $this->readState();
        $lastWeekly = $state['last_weekly_execute'] ?? null;

        if (! is_array($lastWeekly)) {
            return;
        }

        if (($lastWeekly['success'] ?? null) === false) {
            $anomalies[] = 'Previous weekly email retention execution did not complete successfully.';
        }
    }

    /**
     * @param  list<string>  $anomalies
     */
    private function collectSafetyAnomalies(
        RetentionUnknownCustomerPruneSummary $unknownSummary,
        RetentionIgnoredEmailPruneSummary $noiseSummary,
        array &$anomalies,
    ): void {
        if ($unknownSummary->candidatesWithOrderId > 0) {
            $anomalies[] = 'unknown_customer candidate set includes order-linked rows.';
        }

        if ($unknownSummary->candidatesWithIncidentId > 0) {
            $anomalies[] = 'unknown_customer candidate set includes incident-linked rows.';
        }

        if ($noiseSummary->candidatesWithOrderId > 0) {
            $anomalies[] = 'Approved-noise candidate set includes order-linked rows.';
        }

        if ($noiseSummary->candidatesWithIncidentId > 0) {
            $anomalies[] = 'Approved-noise candidate set includes incident-linked rows.';
        }

        $unexpectedNoiseReasons = array_diff(
            array_keys(array_filter($noiseSummary->candidatesByIgnoreReason)),
            $this->approvedNoiseIgnoreReasons(),
        );

        if ($unexpectedNoiseReasons !== []) {
            $anomalies[] = 'Unexpected ignore reason in approved-noise candidates: '.implode(', ', $unexpectedNoiseReasons);
        }

        if ($this->countOrderLinkedUnknownCustomerOutsideCandidates() > 0) {
            $anomalies[] = 'Order-linked unknown_customer rows exist in the ignored population.';
        }

        if ($this->countPendingOutboxEligibleUnknownCustomer() > 0) {
            $anomalies[] = 'Pending/processing inbound outbox dependencies exist on eligible unknown_customer rows.';
        }
    }

    private function countOrderLinkedUnknownCustomerOutsideCandidates(): int
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return 0;
        }

        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where('ignore_reason', 'unknown_customer')
            ->whereNotNull('order_id')
            ->count();
    }

    private function countPendingOutboxEligibleUnknownCustomer(): int
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return 0;
        }

        $cutoff = app(RetentionUnknownCustomerInspectionService::class)->receivedAtCutoff();

        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where('ignore_reason', 'unknown_customer')
            ->whereNull('order_id')
            ->whereNull('incident_id')
            ->where('received_at', '<', $cutoff)
            ->whereNotNull('processed_at')
            ->whereExists(function ($subquery): void {
                app(RetentionIgnoredEmailInspectionService::class)->applyPendingInboundOutboxExists($subquery);
            })
            ->count();
    }

    /**
     * @param  array<string, mixed>  $previousState
     * @param  list<string>  $anomalies
     */
    private function collectGrowthAnomalies(
        RetentionUnknownCustomerPruneSummary $unknownSummary,
        RetentionIgnoredEmailPruneSummary $noiseSummary,
        array $previousState,
        array &$anomalies,
        bool $abortOnGrowth,
    ): void {
        $variancePercent = max(0, (int) config('retention.email_retention.candidate_growth_abort_percent', 20));
        $previousDaily = is_array($previousState['last_daily_dry_run'] ?? null)
            ? $previousState['last_daily_dry_run']
            : null;

        if ($previousDaily === null) {
            return;
        }

        $this->appendGrowthAnomaly(
            label: 'unknown_customer',
            current: $unknownSummary->candidateCount,
            previous: (int) ($previousDaily['unknown_customer_candidates'] ?? 0),
            variancePercent: $variancePercent,
            anomalies: $anomalies,
            abortOnGrowth: $abortOnGrowth,
        );

        $this->appendGrowthAnomaly(
            label: 'approved_noise',
            current: $noiseSummary->candidateCount,
            previous: (int) ($previousDaily['noise_candidates'] ?? 0),
            variancePercent: $variancePercent,
            anomalies: $anomalies,
            abortOnGrowth: $abortOnGrowth,
        );
    }

    /**
     * @param  list<string>  $anomalies
     */
    private function appendGrowthAnomaly(
        string $label,
        int $current,
        int $previous,
        int $variancePercent,
        array &$anomalies,
        bool $abortOnGrowth,
    ): void {
        if ($previous <= 0 || $current <= $previous) {
            return;
        }

        $upperBound = (int) floor($previous * (100 + $variancePercent) / 100);

        if ($current > $upperBound) {
            $message = sprintf(
                '%s candidate count increased by more than %d%% (%d -> %d).',
                $label,
                $variancePercent,
                $previous,
                $current,
            );

            if ($abortOnGrowth) {
                $anomalies[] = $message;
            }
        }
    }

    /**
     * @return array{needs_review: int, order_linked: int}
     */
    private function collectMonitoringMetrics(): array
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return ['needs_review' => 0, 'order_linked' => 0];
        }

        return [
            'needs_review' => IncomingEmailMessage::query()
                ->where('status', IncomingEmailMessageStatus::NeedsReview)
                ->count(),
            'order_linked' => IncomingEmailMessage::query()
                ->whereNotNull('order_id')
                ->count(),
        ];
    }

    private function manifestDirectory(): string
    {
        $directory = config('retention.email_retention.manifest_directory')
            ?? config('retention.unknown_customer.manifest_directory')
            ?? storage_path('app/retention-manifests');

        return rtrim((string) $directory, '/');
    }

    private function statePath(): string
    {
        return $this->manifestDirectory().'/schedule-state.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $path = $this->statePath();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array{needs_review: int, order_linked: int}  $metrics
     * @param  list<string>  $anomalies
     */
    private function writeState(
        string $mode,
        bool $success,
        RetentionUnknownCustomerPruneSummary $unknownSummary,
        RetentionIgnoredEmailPruneSummary $noiseSummary,
        int $unknownDeleted,
        int $noiseDeleted,
        int $postUnknownCandidates,
        int $postNoiseCandidates,
        Carbon $finishedAt,
        array $anomalies,
        array $metrics,
    ): void {
        $state = $this->readState();

        $state['last_daily_dry_run'] = [
            'at' => $finishedAt->toIso8601String(),
            'success' => $success,
            'unknown_customer_candidates' => $unknownSummary->candidateCount,
            'noise_candidates' => $noiseSummary->candidateCount,
            'needs_review_backlog' => $metrics['needs_review'],
            'order_linked_count' => $metrics['order_linked'],
            'anomalies' => $anomalies,
        ];

        if ($mode === self::MODE_WEEKLY_EXECUTE) {
            $state['last_weekly_execute'] = [
                'at' => $finishedAt->toIso8601String(),
                'success' => $success,
                'unknown_customer_deleted' => $unknownDeleted,
                'noise_deleted' => $noiseDeleted,
                'post_unknown_customer_candidates' => $postUnknownCandidates,
                'post_noise_candidates' => $postNoiseCandidates,
                'anomalies' => $anomalies,
            ];
        }

        File::put($this->statePath(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @param  array{needs_review: int, order_linked: int}  $metrics
     * @param  list<string>  $anomalies
     */
    private function writeAuditLog(
        string $mode,
        string $timestamp,
        RetentionUnknownCustomerPruneSummary $unknownSummary,
        RetentionIgnoredEmailPruneSummary $noiseSummary,
        array $metrics,
        int $unknownDeleted,
        int $noiseDeleted,
        int $unknownBatches,
        int $noiseBatches,
        int $postUnknownCandidates,
        int $postNoiseCandidates,
        array $anomalies,
        bool $success,
        bool $aborted,
        ?string $abortReason,
        Carbon $startedAt,
        Carbon $finishedAt,
    ): string {
        $path = $this->manifestDirectory().'/schedule-'.$mode.'-'.$timestamp.'.json';
        $payload = [
            'mode' => $mode,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'success' => $success,
            'aborted' => $aborted,
            'abort_reason' => $abortReason,
            'retention_thresholds' => [
                'unknown_customer_days' => $unknownSummary->unknownCustomerDays,
                'ignored_email_days' => $noiseSummary->ignoredEmailDays,
            ],
            'commands' => [
                'unknown_customer' => 'database:retention-prune-unknown-customer',
                'approved_noise' => 'database:retention-prune-ignored-email',
            ],
            'unknown_customer' => [
                'candidate_count' => $unknownSummary->candidateCount,
                'age_distribution' => $unknownSummary->candidatesByAgeBucket,
                'oldest_received_at' => $unknownSummary->oldestCandidateReceivedAt,
                'newest_received_at' => $unknownSummary->newestCandidateReceivedAt,
                'estimated_bytes' => $unknownSummary->estimatedPayloadBytes,
                'manifest_path' => $unknownSummary->manifestPath,
                'manifest_sha256' => $unknownSummary->manifestSha256,
                'safety_failures' => $unknownSummary->integrityFailureCount(),
                'deleted' => $unknownDeleted,
                'batches' => $unknownBatches,
                'post_candidate_count' => $postUnknownCandidates,
            ],
            'approved_noise' => [
                'candidate_count' => $noiseSummary->candidateCount,
                'ignore_reason_distribution' => $noiseSummary->candidatesByIgnoreReason,
                'age_distribution' => $noiseSummary->candidatesByAgeBucket,
                'oldest_received_at' => $noiseSummary->oldestCandidateReceivedAt,
                'newest_received_at' => $noiseSummary->newestCandidateReceivedAt,
                'estimated_bytes' => $noiseSummary->estimatedPayloadBytes,
                'manifest_path' => $noiseSummary->manifestPath,
                'manifest_sha256' => $this->hashFile($noiseSummary->manifestPath),
                'safety_failures' => $noiseSummary->integrityFailureCount(),
                'deleted' => $noiseDeleted,
                'batches' => $noiseBatches,
                'post_candidate_count' => $postNoiseCandidates,
            ],
            'monitoring' => [
                'needs_review_backlog' => $metrics['needs_review'],
                'order_linked_count' => $metrics['order_linked'],
            ],
            'anomalies' => $anomalies,
        ];

        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    private function pruneOldArtifacts(string $manifestDirectory): void
    {
        $keepDaily = max(1, (int) config('retention.email_retention.keep_daily_audit_logs', 30));
        $keepWeekly = max(1, (int) config('retention.email_retention.keep_weekly_audit_logs', 12));

        $this->pruneMatchingFiles($manifestDirectory.'/schedule-daily_dry_run-*.json', $keepDaily);
        $this->pruneMatchingFiles($manifestDirectory.'/schedule-weekly_execute-*.json', $keepWeekly);
    }

    private function pruneMatchingFiles(string $pattern, int $keep): void
    {
        $files = glob($pattern) ?: [];

        if (count($files) <= $keep) {
            return;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $toDelete = array_slice($files, $keep);

        foreach ($toDelete as $path) {
            @unlink($path);
        }
    }

    private function ensureManifestDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create manifest directory: %s', $directory));
        }
    }

    private function acquireProcessLock(): void
    {
        if (filter_var(env('RETENTION_EMAIL_SCHEDULE_SKIP_LOCK', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $lockPath = (string) config('retention.email_retention.lock_file', '/var/lock/radium-desk-email-retention.lock');
        $handle = fopen($lockPath, 'c');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open retention lock file: %s', $lockPath));
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new RuntimeException('Email retention schedule aborted: concurrent retention process detected.');
        }

        $this->lockHandle = $handle;
    }

    private function releaseProcessLock(): void
    {
        if (! is_resource($this->lockHandle)) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    private function hashFile(?string $path): ?string
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return is_string($hash) ? $hash : null;
    }
}
