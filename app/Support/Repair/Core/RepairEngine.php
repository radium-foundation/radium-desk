<?php

namespace App\Support\Repair\Core;

use App\Support\Repair\Contracts\RepairDefinition;
use App\Support\Repair\Data\RepairBatchOptions;
use App\Support\Repair\Data\RepairBatchSummary;
use App\Support\Repair\Enums\RepairBatchStatus;
use App\Support\Repair\Enums\RepairPhase;
use App\Support\Repair\Models\SystemRepairBatch;
use App\Support\Repair\Services\RepairExportService;
use App\Support\Repair\Services\RepairLockService;
use App\Support\Repair\Services\RepairProgressReporter;
use App\Support\Repair\Services\RepairRollbackService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RepairEngine
{
    public function __construct(
        private readonly RepairLockService $lockService,
        private readonly BatchExecutor $batchExecutor,
        private readonly RepairRollbackService $rollbackService,
        private readonly RepairExportService $exportService,
    ) {}

    public function run(
        RepairDefinition $definition,
        RepairBatchOptions $options,
        ?RepairProgressReporter $reporter = null,
    ): RepairBatchSummary {
        $reporter ??= new RepairProgressReporter;

        return match ($options->phase) {
            RepairPhase::Rollback => $this->runRollback($definition, $options, $reporter),
            RepairPhase::Verify => $this->runVerify($definition, $options, $reporter),
            default => $this->runPreviewOrExecute($definition, $options, $reporter),
        };
    }

    private function runPreviewOrExecute(
        RepairDefinition $definition,
        RepairBatchOptions $options,
        RepairProgressReporter $reporter,
    ): RepairBatchSummary {
        $startedAt = microtime(true);
        $this->lockService->acquire($definition->key());

        try {
            $batch = $this->resolveOrCreateBatch($definition, $options);
            $context = new RepairContext(
                options: $options,
                batch: $batch,
                silent: ! $options->notify,
            );
            app()->instance(RepairContext::class, $context);

            $resolver = $definition->candidateResolver();
            $total = $resolver->count($options);
            $limit = $options->limit ?? $definition->defaultLimit() ?? (int) config('repair.default_limit', 100);
            $max = min($definition->maxBatchSize(), (int) config('repair.max_batch_size', 500));

            if ($limit > $max) {
                throw new RuntimeException(sprintf(
                    'Limit %d exceeds max batch size %d for %s.',
                    $limit,
                    $max,
                    $definition->key(),
                ));
            }

            $effectiveOptions = new RepairBatchOptions(
                phase: $options->phase,
                dryRun: $options->dryRun,
                execute: $options->execute,
                force: $options->force,
                quiet: $options->quiet,
                json: $options->json,
                csv: $options->csv,
                resume: $options->resume,
                batchUuid: $batch->uuid,
                limit: $limit,
                offset: $options->offset,
                since: $options->since,
                until: $options->until,
                exportPath: $options->exportPath,
                checkpointEvery: $options->checkpointEvery,
                notify: $options->notify,
                extras: $options->extras,
            );

            $displayTotal = min($total, $limit);
            $reporter->batchStarted(
                $definition->key(),
                $effectiveOptions->phase->value,
                $batch->uuid,
                $displayTotal,
            );

            $counts = [
                'scanned' => 0,
                'processed' => 0,
                'repaired' => 0,
                'cleaned_up' => 0,
                'skipped' => 0,
                'failed' => 0,
                'rolled_back' => 0,
            ];

            if ($options->phase === RepairPhase::Execute && $options->execute) {
                $batch->update([
                    'status' => RepairBatchStatus::Running,
                    'phase' => RepairPhase::Execute,
                    'started_at' => $batch->started_at ?? now(),
                ]);
            }

            $result = $this->batchExecutor->run(
                definition: $definition,
                batch: $batch,
                context: $context->withBatch($batch),
                options: $effectiveOptions,
                candidates: $resolver->iterate($effectiveOptions),
                total: max($displayTotal, 1),
                counts: $counts,
                samples: [],
                failures: [],
                reporter: $reporter,
            );

            $status = $this->resolveStatus($effectiveOptions, $result['counts']);
            $summary = new RepairBatchSummary(
                batchUuid: $batch->uuid,
                repairKey: $definition->key(),
                phase: $effectiveOptions->phase,
                status: $status,
                counts: $result['counts'],
                elapsedSeconds: round(microtime(true) - $startedAt, 2),
                samples: $result['samples'],
                failures: $result['failures'],
            );

            $freshBatch = $batch->fresh(['items']) ?? $batch;
            $paths = $this->exportService->export(
                batch: $freshBatch,
                summary: $summary,
                writeJson: true,
                writeCsv: $options->csv,
                exportPath: $options->exportPath,
            );

            $summary = new RepairBatchSummary(
                batchUuid: $summary->batchUuid,
                repairKey: $summary->repairKey,
                phase: $summary->phase,
                status: $summary->status,
                counts: $summary->counts,
                elapsedSeconds: $summary->elapsedSeconds,
                samples: $summary->samples,
                failures: $summary->failures,
                reportPaths: $paths,
            );

            $batch->update([
                'status' => $status,
                'phase' => $effectiveOptions->phase,
                'completed_at' => now(),
                'counts' => $result['counts'],
                'report_paths' => $paths,
            ]);

            Log::info('system_repair.batch_completed', $summary->toArray());
            $reporter->summary($summary);

            return $summary;
        } catch (Throwable $exception) {
            Log::error('system_repair.batch_failed', [
                'repair_key' => $definition->key(),
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            app()->forgetInstance(RepairContext::class);
            $this->lockService->release();
        }
    }

    private function runRollback(
        RepairDefinition $definition,
        RepairBatchOptions $options,
        RepairProgressReporter $reporter,
    ): RepairBatchSummary {
        if ($options->batchUuid === null || $options->batchUuid === '') {
            throw new RuntimeException('--batch is required for rollback.');
        }

        $batch = SystemRepairBatch::query()->where('uuid', $options->batchUuid)->first();
        if ($batch === null) {
            throw new RuntimeException('Repair batch not found: '.$options->batchUuid);
        }

        if ($batch->repair_key !== $definition->key()) {
            throw new RuntimeException(sprintf(
                'Batch %s belongs to %s, not %s.',
                $batch->uuid,
                $batch->repair_key,
                $definition->key(),
            ));
        }

        $this->lockService->acquire($definition->key());

        try {
            $context = new RepairContext(
                options: $options,
                batch: $batch,
                silent: true,
            );
            app()->instance(RepairContext::class, $context);

            return $this->rollbackService->rollback(
                definition: $definition,
                batch: $batch,
                options: $options,
                context: $context,
                reporter: $reporter,
            );
        } finally {
            app()->forgetInstance(RepairContext::class);
            $this->lockService->release();
        }
    }

    private function runVerify(
        RepairDefinition $definition,
        RepairBatchOptions $options,
        RepairProgressReporter $reporter,
    ): RepairBatchSummary {
        if ($options->batchUuid === null) {
            throw new RuntimeException('--batch is required for --verify-only.');
        }

        $batch = SystemRepairBatch::query()->where('uuid', $options->batchUuid)->firstOrFail();
        $verifier = $definition->verifier();
        $startedAt = microtime(true);
        $report = $verifier?->verifyBatch($batch);

        $summary = new RepairBatchSummary(
            batchUuid: $batch->uuid,
            repairKey: $definition->key(),
            phase: RepairPhase::Verify,
            status: $batch->status,
            counts: [
                'checked' => $report?->checked ?? 0,
                'passed' => $report?->passed ?? 0,
                'failed' => $report?->failed ?? 0,
                'scanned' => 0,
                'processed' => 0,
                'repaired' => 0,
                'cleaned_up' => 0,
                'skipped' => 0,
                'rolled_back' => 0,
            ],
            elapsedSeconds: round(microtime(true) - $startedAt, 2),
            samples: $report?->items ?? [],
            failures: array_values(array_filter(
                $report?->items ?? [],
                fn (array $item): bool => ($item['ok'] ?? true) === false,
            )),
            errorSummary: $report?->summary,
        );

        $reporter->summary($summary);

        return $summary;
    }

    private function resolveOrCreateBatch(
        RepairDefinition $definition,
        RepairBatchOptions $options,
    ): SystemRepairBatch {
        if ($options->batchUuid !== null && $options->phase === RepairPhase::Execute) {
            $existing = SystemRepairBatch::query()->where('uuid', $options->batchUuid)->first();
            if ($existing === null) {
                throw new RuntimeException('Preview batch not found: '.$options->batchUuid);
            }

            if (! in_array($existing->status, [
                RepairBatchStatus::Previewed,
                RepairBatchStatus::Approved,
                RepairBatchStatus::CompletedWithErrors,
                RepairBatchStatus::Failed,
                RepairBatchStatus::Running,
            ], true) && ! $options->resume && ! $options->force) {
                throw new RuntimeException(sprintf(
                    'Batch %s cannot be executed from status %s.',
                    $existing->uuid,
                    $existing->status->value,
                ));
            }

            return $existing;
        }

        return SystemRepairBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'repair_key' => $definition->key(),
            'status' => $options->phase === RepairPhase::Preview
                ? RepairBatchStatus::Previewed
                : RepairBatchStatus::Running,
            'phase' => $options->phase,
            'options' => [
                'limit' => $options->limit,
                'offset' => $options->offset,
                'since' => $options->since,
                'until' => $options->until,
                'extras' => $options->extras,
                'execute' => $options->execute,
                'dry_run' => $options->dryRun,
            ],
            'environment' => (string) config('app.env'),
            'initiated_by' => get_current_user() ?: null,
            'started_at' => now(),
            'counts' => [],
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function resolveStatus(RepairBatchOptions $options, array $counts): RepairBatchStatus
    {
        if ($options->phase === RepairPhase::Preview || $options->dryRun || ! $options->execute) {
            return RepairBatchStatus::Previewed;
        }

        if (($counts['failed'] ?? 0) > 0) {
            return RepairBatchStatus::CompletedWithErrors;
        }

        return RepairBatchStatus::Completed;
    }
}
