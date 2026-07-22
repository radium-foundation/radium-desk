<?php

namespace App\Support\Repair\Services;

use App\Support\Repair\Contracts\RepairDefinition;
use App\Support\Repair\Core\RepairContext;
use App\Support\Repair\Data\RepairBatchOptions;
use App\Support\Repair\Data\RepairBatchSummary;
use App\Support\Repair\Data\RepairCandidate;
use App\Support\Repair\Enums\RepairBatchStatus;
use App\Support\Repair\Enums\RepairItemOutcome;
use App\Support\Repair\Enums\RepairPhase;
use App\Support\Repair\Models\SystemRepairBatch;
use App\Support\Repair\Models\SystemRepairItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RepairRollbackService
{
    public function __construct(
        private readonly RepairAuditBridge $auditBridge,
        private readonly RepairExportService $exportService,
    ) {}

    public function rollback(
        RepairDefinition $definition,
        SystemRepairBatch $batch,
        RepairBatchOptions $options,
        RepairContext $context,
        ?RepairProgressReporter $reporter = null,
    ): RepairBatchSummary {
        $reporter ??= new RepairProgressReporter;
        $startedAt = microtime(true);

        if ($batch->status === RepairBatchStatus::RolledBack) {
            return $this->summary($batch, $options, $startedAt, error: 'Batch already rolled back.', reporter: $reporter);
        }

        if (! in_array($batch->status, [
            RepairBatchStatus::Completed,
            RepairBatchStatus::CompletedWithErrors,
            RepairBatchStatus::Failed,
        ], true)) {
            return $this->summary(
                $batch,
                $options,
                $startedAt,
                error: sprintf('Batch status "%s" cannot be rolled back.', $batch->status->value),
                reporter: $reporter,
            );
        }

        $batch->update([
            'status' => RepairBatchStatus::RollingBack,
            'phase' => RepairPhase::Rollback,
        ]);

        $counts = [
            'scanned' => 0,
            'processed' => 0,
            'repaired' => 0,
            'cleaned_up' => 0,
            'skipped' => 0,
            'failed' => 0,
            'rolled_back' => 0,
        ];
        $failures = [];
        $samples = [];
        $handler = $definition->itemHandler();

        $items = $batch->items()
            ->whereIn('outcome', [
                RepairItemOutcome::Repaired->value,
                RepairItemOutcome::CleanedUp->value,
            ])
            ->orderByDesc('id')
            ->get();

        foreach ($items as $item) {
            $counts['scanned']++;
            $counts['processed']++;

            if ($options->dryRun || ! $options->execute) {
                $counts['rolled_back']++;
                $samples[] = [
                    'action' => 'would_rollback',
                    'subject_key' => $item->subject_key,
                    'item_id' => $item->id,
                ];

                continue;
            }

            try {
                DB::transaction(function () use ($definition, $handler, $item, $context, $batch): void {
                    $subject = $item->subject_type::query()->find($item->subject_id);
                    if ($subject === null) {
                        throw new \RuntimeException('Subject no longer exists.');
                    }

                    $related = null;
                    if ($item->related_type !== null && $item->related_id !== null) {
                        $related = $item->related_type::query()->find($item->related_id);
                    }

                    $candidate = new RepairCandidate(
                        subject: $subject,
                        subjectKey: (string) $item->subject_key,
                        related: $related,
                        meta: is_array($item->meta) ? $item->meta : [],
                    );

                    $before = is_array($item->before_snapshot) ? $item->before_snapshot : [];
                    $handler->restoreSnapshot($candidate, $before, $context);
                    $this->auditBridge->logItemRolledBack($batch, $subject, $before);

                    $item->update([
                        'outcome' => RepairItemOutcome::RolledBack,
                        'finished_at' => now(),
                    ]);
                });

                $counts['rolled_back']++;
                $samples[] = [
                    'action' => 'rolled_back',
                    'subject_key' => $item->subject_key,
                    'item_id' => $item->id,
                ];
            } catch (Throwable $exception) {
                $counts['failed']++;
                $failures[] = [
                    'subject_key' => $item->subject_key,
                    'error' => $exception->getMessage(),
                ];
                Log::warning('system_repair.rollback_item_failed', [
                    'batch_uuid' => $batch->uuid,
                    'item_id' => $item->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $status = $counts['failed'] > 0
            ? RepairBatchStatus::CompletedWithErrors
            : RepairBatchStatus::RolledBack;

        if (! $options->dryRun && $options->execute && $counts['failed'] === 0) {
            $status = RepairBatchStatus::RolledBack;
        }

        $summary = new RepairBatchSummary(
            batchUuid: $batch->uuid,
            repairKey: $definition->key(),
            phase: RepairPhase::Rollback,
            status: $options->dryRun || ! $options->execute ? RepairBatchStatus::Previewed : $status,
            counts: $counts,
            elapsedSeconds: round(microtime(true) - $startedAt, 2),
            samples: $samples,
            failures: $failures,
        );

        if ($options->execute && ! $options->dryRun) {
            $paths = $this->exportService->export(
                batch: $batch->fresh(['items']) ?? $batch,
                summary: $summary,
                writeJson: $options->json || true,
                writeCsv: $options->csv,
                exportPath: $options->exportPath,
            );
            $summary = new RepairBatchSummary(
                batchUuid: $summary->batchUuid,
                repairKey: $summary->repairKey,
                phase: $summary->phase,
                status: $status,
                counts: $summary->counts,
                elapsedSeconds: $summary->elapsedSeconds,
                samples: $summary->samples,
                failures: $summary->failures,
                reportPaths: $paths,
            );

            $batch->update([
                'status' => $status,
                'phase' => RepairPhase::Rollback,
                'completed_at' => now(),
                'counts' => $counts,
                'report_paths' => $paths,
            ]);
        }

        $reporter->summary($summary);

        return $summary;
    }

    private function summary(
        SystemRepairBatch $batch,
        RepairBatchOptions $options,
        float $startedAt,
        ?string $error = null,
        ?RepairProgressReporter $reporter = null,
    ): RepairBatchSummary {
        $summary = new RepairBatchSummary(
            batchUuid: $batch->uuid,
            repairKey: $batch->repair_key,
            phase: RepairPhase::Rollback,
            status: $batch->status,
            counts: is_array($batch->counts) ? $batch->counts : [],
            elapsedSeconds: round(microtime(true) - $startedAt, 2),
            errorSummary: $error,
        );
        ($reporter ?? new RepairProgressReporter)->summary($summary);

        return $summary;
    }
}
