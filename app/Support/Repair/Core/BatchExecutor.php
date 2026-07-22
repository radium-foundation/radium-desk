<?php

namespace App\Support\Repair\Core;

use App\Support\Repair\Contracts\RepairDefinition;
use App\Support\Repair\Data\RepairActionOutcome;
use App\Support\Repair\Data\RepairBatchOptions;
use App\Support\Repair\Data\RepairCandidate;
use App\Support\Repair\Data\RepairClassification;
use App\Support\Repair\Data\RepairProgress;
use App\Support\Repair\Enums\RepairItemOutcome;
use App\Support\Repair\Enums\RepairPhase;
use App\Support\Repair\Models\SystemRepairBatch;
use App\Support\Repair\Models\SystemRepairItem;
use App\Support\Repair\Services\RepairAuditBridge;
use App\Support\Repair\Services\RepairCheckpointService;
use App\Support\Repair\Services\RepairProgressReporter;
use App\Support\Repair\Services\RepairSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BatchExecutor
{
    public function __construct(
        private readonly RepairSnapshotService $snapshotService,
        private readonly RepairCheckpointService $checkpointService,
        private readonly RepairAuditBridge $auditBridge,
        private readonly RepairProgressReporter $progressReporter,
    ) {}

    /**
     * @param  iterable<RepairCandidate>  $candidates
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $samples
     * @param  list<array<string, mixed>>  $failures
     * @return array{counts: array<string, int>, samples: list<array<string, mixed>>, failures: list<array<string, mixed>>}
     */
    public function run(
        RepairDefinition $definition,
        SystemRepairBatch $batch,
        RepairContext $context,
        RepairBatchOptions $options,
        iterable $candidates,
        int $total,
        array $counts,
        array $samples,
        array $failures,
        ?RepairProgressReporter $reporter = null,
    ): array {
        $reporter ??= $this->progressReporter;
        $handler = $definition->itemHandler();
        $resolver = $definition->candidateResolver();
        $processedInRun = 0;
        $checkpointEvery = max(1, $options->checkpointEvery);

        foreach ($candidates as $candidate) {
            if ($options->limit !== null && $processedInRun >= $options->limit) {
                break;
            }

            $counts['scanned']++;

            if ($options->offset > 0 && ($counts['scanned'] - 1) < $options->offset) {
                continue;
            }

            $classification = $resolver->classify($candidate, $options);
            $processedInRun++;
            $counts['processed']++;

            $outcome = $this->processOne(
                definition: $definition,
                batch: $batch,
                context: $context,
                options: $options,
                candidate: $candidate,
                classification: $classification,
            );

            $this->bumpCounts($counts, $outcome);

            if (count($samples) < 20) {
                $samples[] = [
                    'subject_key' => $candidate->subjectKey,
                    'action' => $outcome->action,
                    'category' => $outcome->category,
                    'outcome' => $outcome->outcome->value,
                    'skip_reason' => $outcome->skipReason,
                    'messages' => $outcome->messages,
                ];
            }

            if ($outcome->isFailure()) {
                $failures[] = [
                    'subject_key' => $candidate->subjectKey,
                    'error' => $outcome->errorMessage ?? 'unknown',
                ];
            }

            $reporter->item(new RepairProgress(
                processed: $counts['processed'],
                total: $total,
                repaired: $counts['repaired'],
                cleanedUp: $counts['cleaned_up'],
                skipped: $counts['skipped'],
                failed: $counts['failed'],
                currentSubjectKey: $candidate->subjectKey,
                currentAction: $outcome->action,
                currentOutcome: $outcome->outcome->value,
            ));

            if ($processedInRun % $checkpointEvery === 0) {
                $this->checkpointService->save($batch, $candidate->subjectId(), $counts);
            }
        }

        $this->checkpointService->save($batch, null, $counts);

        if ($context->isExecute()) {
            $handler->afterBatch($context);
        }

        return compact('counts', 'samples', 'failures');
    }

    private function processOne(
        RepairDefinition $definition,
        SystemRepairBatch $batch,
        RepairContext $context,
        RepairBatchOptions $options,
        RepairCandidate $candidate,
        RepairClassification $classification,
    ): RepairActionOutcome {
        $handler = $definition->itemHandler();

        if ($classification->shouldSkip() || $handler->isIdempotentNoOp($candidate, $classification)) {
            $reason = $classification->skipReason ?? 'idempotent_no_op';
            $outcome = $options->phase === RepairPhase::Preview
                ? RepairActionOutcome::would(
                    outcome: RepairItemOutcome::WouldSkip,
                    action: 'skip',
                    category: $classification->category,
                    messages: ['Skipped'],
                    skipReason: $reason,
                )
                : RepairActionOutcome::skipped(
                    action: 'skip',
                    category: $classification->category,
                    reason: $reason,
                );

            $this->persistItem($batch, $candidate, $classification, $outcome, before: null);

            return $outcome;
        }

        if ($options->phase === RepairPhase::Preview || $context->isDryRun()) {
            $outcome = $handler->preview($candidate, $classification, $context);
            $this->persistItem($batch, $candidate, $classification, $outcome, before: null);

            return $outcome;
        }

        try {
            return DB::transaction(function () use (
                $handler,
                $batch,
                $context,
                $candidate,
                $classification,
            ): RepairActionOutcome {
                $before = $this->snapshotService->capture($handler, $candidate);
                $outcome = $handler->execute($candidate, $classification, $context);

                if ($outcome->isSuccessMutation()) {
                    $this->auditBridge->logItemRepaired($batch, $candidate, $outcome);
                }

                $this->persistItem($batch, $candidate, $classification, $outcome, $before);

                return $outcome;
            });
        } catch (Throwable $exception) {
            Log::warning('system_repair.item_failed', [
                'batch_uuid' => $batch->uuid,
                'subject_key' => $candidate->subjectKey,
                'message' => $exception->getMessage(),
            ]);

            $outcome = RepairActionOutcome::failed(
                action: $classification->action,
                category: $classification->category,
                errorMessage: $exception->getMessage(),
                exception: $exception,
            );

            $this->persistItem($batch, $candidate, $classification, $outcome, before: null);

            return $outcome;
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function persistItem(
        SystemRepairBatch $batch,
        RepairCandidate $candidate,
        RepairClassification $classification,
        RepairActionOutcome $outcome,
        ?array $before,
    ): void {
        $relatedId = $candidate->relatedId() ?? 0;

        $existing = SystemRepairItem::query()
            ->where('batch_id', $batch->id)
            ->where('subject_type', $candidate->subjectType())
            ->where('subject_id', $candidate->subjectId())
            ->where('related_id', $relatedId)
            ->first();

        $payload = [
            'repair_key' => $batch->repair_key,
            'subject_key' => $candidate->subjectKey,
            'related_type' => $candidate->relatedType(),
            'action' => $outcome->action,
            'category' => $outcome->category !== '' ? $outcome->category : $classification->category,
            'outcome' => $outcome->outcome,
            'skip_reason' => $outcome->skipReason,
            'error_message' => $outcome->errorMessage,
            'before_snapshot' => $before,
            'after_snapshot' => $outcome->afterSnapshot,
            'attempts' => ($existing?->attempts ?? 0) + 1,
            'started_at' => now(),
            'finished_at' => now(),
            'meta' => array_merge($candidate->meta, $outcome->meta),
        ];

        if ($existing !== null) {
            $existing->update($payload);

            return;
        }

        SystemRepairItem::query()->create([
            'batch_id' => $batch->id,
            'subject_type' => $candidate->subjectType(),
            'subject_id' => $candidate->subjectId(),
            'related_id' => $relatedId,
            ...$payload,
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function bumpCounts(array &$counts, RepairActionOutcome $outcome): void
    {
        match ($outcome->outcome) {
            RepairItemOutcome::Repaired, RepairItemOutcome::WouldRepair => $counts['repaired']++,
            RepairItemOutcome::CleanedUp, RepairItemOutcome::WouldCleanup => $counts['cleaned_up']++,
            RepairItemOutcome::Skipped, RepairItemOutcome::WouldSkip => $counts['skipped']++,
            RepairItemOutcome::Failed => $counts['failed']++,
            default => null,
        };
    }
}
