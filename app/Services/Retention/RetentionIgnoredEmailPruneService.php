<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionIgnoredEmailPruneSummary;
use App\Models\IncomingEmailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RetentionIgnoredEmailPruneService
{
    public function __construct(
        private readonly RetentionIgnoredEmailInspectionService $inspectionService,
    ) {}

    public function prune(
        bool $dryRun = true,
        ?int $batchSize = null,
        ?int $limit = null,
        ?Carbon $at = null,
        ?string $manifestPath = null,
    ): RetentionIgnoredEmailPruneSummary {
        $at ??= now();
        $batchSize = max(1, $batchSize ?? (int) config('retention.ignored_email.prune_batch_size', 10000));

        if (! Schema::hasTable('incoming_email_messages')) {
            $cutoff = $this->inspectionService->receivedAtCutoff($at);

            return $this->emptyExecuteSummary(
                at: $at,
                dryRun: $dryRun,
                cutoff: $cutoff,
                batchSize: $batchSize,
                manifestPath: $manifestPath,
            );
        }

        $inspection = $this->inspectionService->inspect($at, $manifestPath);
        $this->assertIntegrity($inspection);

        if ($dryRun) {
            return $inspection;
        }

        $deletedCount = 0;
        $batchesProcessed = 0;
        $remainingLimit = $limit !== null ? max(0, $limit) : null;
        $cutoff = $this->inspectionService->receivedAtCutoff($at);
        $candidateQuery = $this->inspectionService->candidateQuery($cutoff);

        while ($remainingLimit === null || $remainingLimit > 0) {
            $currentBatchSize = $remainingLimit === null
                ? $batchSize
                : min($batchSize, $remainingLimit);

            $ids = (clone $candidateQuery)
                ->orderBy('id')
                ->limit($currentBatchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            try {
                $batchDeleted = DB::transaction(function () use ($candidateQuery, $ids): int {
                    $validatedIds = (clone $candidateQuery)
                        ->whereIn('incoming_email_messages.id', $ids->all())
                        ->orderBy('incoming_email_messages.id')
                        ->pluck('incoming_email_messages.id');

                    if ($validatedIds->isEmpty()) {
                        return 0;
                    }

                    return IncomingEmailMessage::query()->whereIn('id', $validatedIds->all())->delete();
                });
            } catch (\Throwable $exception) {
                throw new RuntimeException(
                    'Ignored email retention prune aborted due to database error.',
                    previous: $exception,
                );
            }

            if ($batchDeleted === 0) {
                break;
            }

            $deletedCount += $batchDeleted;
            $batchesProcessed++;

            if ($remainingLimit !== null) {
                $remainingLimit -= $batchDeleted;
            }

            if ($batchDeleted < $currentBatchSize) {
                break;
            }
        }

        return new RetentionIgnoredEmailPruneSummary(
            inspectedAt: $inspection->inspectedAt,
            dryRun: false,
            ignoredEmailDays: $inspection->ignoredEmailDays,
            receivedAtCutoff: $inspection->receivedAtCutoff,
            tableTotalCount: $inspection->tableTotalCount,
            predicateMatchCount: $inspection->predicateMatchCount,
            candidateCount: $inspection->candidateCount,
            candidatesByIgnoreReason: $inspection->candidatesByIgnoreReason,
            candidatesByAgeBucket: $inspection->candidatesByAgeBucket,
            predicateMatchByIgnoreReason: $inspection->predicateMatchByIgnoreReason,
            estimatedPayloadBytes: $inspection->estimatedPayloadBytes,
            oldestCandidateReceivedAt: $inspection->oldestCandidateReceivedAt,
            newestCandidateReceivedAt: $inspection->newestCandidateReceivedAt,
            sampleCandidateIds: $inspection->sampleCandidateIds,
            manifestPath: $inspection->manifestPath,
            manifestIdCount: $inspection->manifestIdCount,
            candidatesWithIncidentId: $inspection->candidatesWithIncidentId,
            candidatesWithOrderId: $inspection->candidatesWithOrderId,
            candidatesWithLinkFk: $inspection->candidatesWithLinkFk,
            candidatesWithOutgoingReplyFk: $inspection->candidatesWithOutgoingReplyFk,
            candidatesWithPendingOutbox: $inspection->candidatesWithPendingOutbox,
            candidatesWithoutProcessedAt: $inspection->candidatesWithoutProcessedAt,
            excludedUnknownCustomerCount: $inspection->excludedUnknownCustomerCount,
            excludedUnapprovedIgnoreReasonCount: $inspection->excludedUnapprovedIgnoreReasonCount,
            deletedCount: $deletedCount,
            batchesProcessed: $batchesProcessed,
            batchSize: $batchSize,
            baselineAnomaly: $inspection->baselineAnomaly,
            baselineCandidateCount: $inspection->baselineCandidateCount,
            baselineVariancePercent: $inspection->baselineVariancePercent,
        );
    }

    private function assertIntegrity(RetentionIgnoredEmailPruneSummary $summary): void
    {
        if ($summary->integrityFailureCount() === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Ignored email retention integrity check failed (incident_id=%d, order_id=%d, link_fk=%d, outgoing_reply_fk=%d, pending_outbox=%d, missing_processed_at=%d).',
            $summary->candidatesWithIncidentId,
            $summary->candidatesWithOrderId,
            $summary->candidatesWithLinkFk,
            $summary->candidatesWithOutgoingReplyFk,
            $summary->candidatesWithPendingOutbox,
            $summary->candidatesWithoutProcessedAt,
        ));
    }

    private function emptyExecuteSummary(
        Carbon $at,
        bool $dryRun,
        Carbon $cutoff,
        int $batchSize,
        ?string $manifestPath,
    ): RetentionIgnoredEmailPruneSummary {
        $summary = $this->inspectionService->inspect($at, $manifestPath);

        return new RetentionIgnoredEmailPruneSummary(
            inspectedAt: $summary->inspectedAt,
            dryRun: $dryRun,
            ignoredEmailDays: $summary->ignoredEmailDays,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            tableTotalCount: $summary->tableTotalCount,
            predicateMatchCount: $summary->predicateMatchCount,
            candidateCount: $summary->candidateCount,
            candidatesByIgnoreReason: $summary->candidatesByIgnoreReason,
            candidatesByAgeBucket: $summary->candidatesByAgeBucket,
            predicateMatchByIgnoreReason: $summary->predicateMatchByIgnoreReason,
            estimatedPayloadBytes: $summary->estimatedPayloadBytes,
            oldestCandidateReceivedAt: $summary->oldestCandidateReceivedAt,
            newestCandidateReceivedAt: $summary->newestCandidateReceivedAt,
            sampleCandidateIds: $summary->sampleCandidateIds,
            manifestPath: $summary->manifestPath,
            manifestIdCount: $summary->manifestIdCount,
            candidatesWithIncidentId: $summary->candidatesWithIncidentId,
            candidatesWithOrderId: $summary->candidatesWithOrderId,
            candidatesWithLinkFk: $summary->candidatesWithLinkFk,
            candidatesWithOutgoingReplyFk: $summary->candidatesWithOutgoingReplyFk,
            candidatesWithPendingOutbox: $summary->candidatesWithPendingOutbox,
            candidatesWithoutProcessedAt: $summary->candidatesWithoutProcessedAt,
            excludedUnknownCustomerCount: $summary->excludedUnknownCustomerCount,
            excludedUnapprovedIgnoreReasonCount: $summary->excludedUnapprovedIgnoreReasonCount,
            deletedCount: 0,
            batchesProcessed: 0,
            batchSize: $batchSize,
            baselineAnomaly: $summary->baselineAnomaly,
            baselineCandidateCount: $summary->baselineCandidateCount,
            baselineVariancePercent: $summary->baselineVariancePercent,
        );
    }
}
