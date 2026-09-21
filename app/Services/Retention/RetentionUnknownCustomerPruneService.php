<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionUnknownCustomerPruneSummary;
use App\Models\IncomingEmailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RetentionUnknownCustomerPruneService
{
    public function __construct(
        private readonly RetentionUnknownCustomerInspectionService $inspectionService,
    ) {}

    public function prune(
        bool $dryRun = true,
        ?int $batchSize = null,
        ?int $limit = null,
        ?Carbon $at = null,
        ?string $manifestPath = null,
    ): RetentionUnknownCustomerPruneSummary {
        $at ??= now();
        $batchSize = max(1, min(
            $batchSize ?? (int) config('retention.unknown_customer.prune_batch_size', 10000),
            (int) config('retention.unknown_customer.prune_batch_size', 10000),
        ));

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
                    'Unknown customer email retention prune aborted due to database error.',
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

        return new RetentionUnknownCustomerPruneSummary(
            inspectedAt: $inspection->inspectedAt,
            dryRun: false,
            unknownCustomerDays: $inspection->unknownCustomerDays,
            receivedAtCutoff: $inspection->receivedAtCutoff,
            tableTotalCount: $inspection->tableTotalCount,
            unknownCustomerIgnoredTotal: $inspection->unknownCustomerIgnoredTotal,
            candidateCount: $inspection->candidateCount,
            candidatesByAgeBucket: $inspection->candidatesByAgeBucket,
            estimatedPayloadBytes: $inspection->estimatedPayloadBytes,
            oldestCandidateReceivedAt: $inspection->oldestCandidateReceivedAt,
            newestCandidateReceivedAt: $inspection->newestCandidateReceivedAt,
            oldestCandidateId: $inspection->oldestCandidateId,
            newestCandidateId: $inspection->newestCandidateId,
            sampleCandidateIds: $inspection->sampleCandidateIds,
            manifestPath: $inspection->manifestPath,
            manifestIdCount: $inspection->manifestIdCount,
            manifestSha256: $inspection->manifestSha256,
            candidatesWithIncidentId: $inspection->candidatesWithIncidentId,
            candidatesWithOrderId: $inspection->candidatesWithOrderId,
            candidatesWithLinkFk: $inspection->candidatesWithLinkFk,
            candidatesWithOutgoingReplyFk: $inspection->candidatesWithOutgoingReplyFk,
            candidatesWithPendingOutbox: $inspection->candidatesWithPendingOutbox,
            candidatesWithoutProcessedAt: $inspection->candidatesWithoutProcessedAt,
            candidatesWithWrongStatus: $inspection->candidatesWithWrongStatus,
            candidatesWithWrongIgnoreReason: $inspection->candidatesWithWrongIgnoreReason,
            deletedCount: $deletedCount,
            batchesProcessed: $batchesProcessed,
            batchSize: $batchSize,
            baselineAnomaly: $inspection->baselineAnomaly,
            baselineCandidateCount: $inspection->baselineCandidateCount,
            baselineVariancePercent: $inspection->baselineVariancePercent,
        );
    }

    private function assertIntegrity(RetentionUnknownCustomerPruneSummary $summary): void
    {
        if ($summary->integrityFailureCount() === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Unknown customer email retention integrity check failed (incident_id=%d, order_id=%d, link_fk=%d, outgoing_reply_fk=%d, pending_outbox=%d).',
            $summary->candidatesWithIncidentId,
            $summary->candidatesWithOrderId,
            $summary->candidatesWithLinkFk,
            $summary->candidatesWithOutgoingReplyFk,
            $summary->candidatesWithPendingOutbox,
        ));
    }

    private function emptyExecuteSummary(
        Carbon $at,
        bool $dryRun,
        Carbon $cutoff,
        int $batchSize,
        ?string $manifestPath,
    ): RetentionUnknownCustomerPruneSummary {
        $summary = $this->inspectionService->inspect($at, $manifestPath);

        return new RetentionUnknownCustomerPruneSummary(
            inspectedAt: $summary->inspectedAt,
            dryRun: $dryRun,
            unknownCustomerDays: $summary->unknownCustomerDays,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            tableTotalCount: $summary->tableTotalCount,
            unknownCustomerIgnoredTotal: $summary->unknownCustomerIgnoredTotal,
            candidateCount: $summary->candidateCount,
            candidatesByAgeBucket: $summary->candidatesByAgeBucket,
            estimatedPayloadBytes: $summary->estimatedPayloadBytes,
            oldestCandidateReceivedAt: $summary->oldestCandidateReceivedAt,
            newestCandidateReceivedAt: $summary->newestCandidateReceivedAt,
            oldestCandidateId: $summary->oldestCandidateId,
            newestCandidateId: $summary->newestCandidateId,
            sampleCandidateIds: $summary->sampleCandidateIds,
            manifestPath: $summary->manifestPath,
            manifestIdCount: $summary->manifestIdCount,
            manifestSha256: $summary->manifestSha256,
            candidatesWithIncidentId: $summary->candidatesWithIncidentId,
            candidatesWithOrderId: $summary->candidatesWithOrderId,
            candidatesWithLinkFk: $summary->candidatesWithLinkFk,
            candidatesWithOutgoingReplyFk: $summary->candidatesWithOutgoingReplyFk,
            candidatesWithPendingOutbox: $summary->candidatesWithPendingOutbox,
            candidatesWithoutProcessedAt: $summary->candidatesWithoutProcessedAt,
            candidatesWithWrongStatus: $summary->candidatesWithWrongStatus,
            candidatesWithWrongIgnoreReason: $summary->candidatesWithWrongIgnoreReason,
            deletedCount: 0,
            batchesProcessed: 0,
            batchSize: $batchSize,
            baselineAnomaly: $summary->baselineAnomaly,
            baselineCandidateCount: $summary->baselineCandidateCount,
            baselineVariancePercent: $summary->baselineVariancePercent,
        );
    }
}
