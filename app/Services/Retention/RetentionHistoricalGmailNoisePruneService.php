<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionHistoricalGmailNoisePruneSummary;
use App\Data\Retention\RetentionHistoricalGmailNoiseSummary;
use App\Models\IncomingEmailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class RetentionHistoricalGmailNoisePruneService
{
    public function __construct(
        private readonly RetentionHistoricalGmailNoiseInspectionService $inspectionService,
    ) {}

    public function prune(
        bool $dryRun = true,
        ?int $batchSize = null,
        ?int $limit = null,
        ?Carbon $at = null,
    ): RetentionHistoricalGmailNoisePruneSummary {
        $at ??= now();
        $batchSize = max(1, $batchSize ?? (int) config('retention.historical_gmail_noise.prune_batch_size', 10000));

        if (! Schema::hasTable('incoming_email_messages')) {
            $cutoff = $this->inspectionService->receivedAtCutoff();

            return $this->emptySummary($at, $dryRun, $cutoff, $batchSize);
        }

        $inspection = $this->inspectionService->inspect($at);
        $tableTotalCount = IncomingEmailMessage::query()->count();

        if ($dryRun) {
            return $this->fromInspection(
                inspection: $inspection,
                dryRun: true,
                tableTotalCount: $tableTotalCount,
                deletedCount: 0,
                batchesProcessed: 0,
                batchSize: $batchSize,
            );
        }

        $deletedCount = 0;
        $batchesProcessed = 0;
        $remainingLimit = $limit !== null ? max(0, $limit) : null;
        $cutoff = $this->inspectionService->receivedAtCutoff();
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

            IncomingEmailMessage::query()->whereIn('id', $ids->all())->delete();

            $batchDeleted = $ids->count();
            $deletedCount += $batchDeleted;
            $batchesProcessed++;

            if ($remainingLimit !== null) {
                $remainingLimit -= $batchDeleted;
            }

            if ($batchDeleted < $currentBatchSize) {
                break;
            }
        }

        return $this->fromInspection(
            inspection: $inspection,
            dryRun: false,
            tableTotalCount: $tableTotalCount,
            deletedCount: $deletedCount,
            batchesProcessed: $batchesProcessed,
            batchSize: $batchSize,
        );
    }

    private function fromInspection(
        RetentionHistoricalGmailNoiseSummary $inspection,
        bool $dryRun,
        int $tableTotalCount,
        int $deletedCount,
        int $batchesProcessed,
        int $batchSize,
    ): RetentionHistoricalGmailNoisePruneSummary {
        return new RetentionHistoricalGmailNoisePruneSummary(
            inspectedAt: $inspection->inspectedAt,
            dryRun: $dryRun,
            receivedAtCutoff: $inspection->receivedAtCutoff,
            tableTotalCount: $tableTotalCount,
            candidateCount: $inspection->candidateCount,
            candidatesByIgnoreReason: $inspection->candidatesByIgnoreReason,
            candidatesByReceivedMonth: $inspection->candidatesByReceivedMonth,
            estimatedPayloadBytes: $inspection->estimatedPayloadBytes,
            sampleCandidateIds: $inspection->sampleCandidateIds,
            candidatesWithIncidentId: $inspection->candidatesWithIncidentId,
            candidatesWithOrderId: $inspection->candidatesWithOrderId,
            candidatesWithLinkFk: $inspection->candidatesWithLinkFk,
            candidatesWithOutgoingReplyFk: $inspection->candidatesWithOutgoingReplyFk,
            excludedUnknownCustomerCount: $inspection->excludedUnknownCustomerCount,
            excludedExplicitMessageIdCount: $inspection->excludedExplicitMessageIdCount,
            deletedCount: $deletedCount,
            batchesProcessed: $batchesProcessed,
            batchSize: $batchSize,
        );
    }

    private function emptySummary(
        Carbon $at,
        bool $dryRun,
        Carbon $cutoff,
        int $batchSize,
    ): RetentionHistoricalGmailNoisePruneSummary {
        return new RetentionHistoricalGmailNoisePruneSummary(
            inspectedAt: $at,
            dryRun: $dryRun,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            tableTotalCount: 0,
            candidateCount: 0,
            candidatesByIgnoreReason: [],
            candidatesByReceivedMonth: [],
            estimatedPayloadBytes: 0,
            sampleCandidateIds: [],
            candidatesWithIncidentId: 0,
            candidatesWithOrderId: 0,
            candidatesWithLinkFk: 0,
            candidatesWithOutgoingReplyFk: 0,
            excludedUnknownCustomerCount: 0,
            excludedExplicitMessageIdCount: 0,
            deletedCount: 0,
            batchesProcessed: 0,
            batchSize: $batchSize,
        );
    }
}
