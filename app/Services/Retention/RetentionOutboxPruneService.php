<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionOutboxPruneSummary;
use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class RetentionOutboxPruneService
{
    /**
     * Prune completed outbox events older than the configured retention window.
     *
     * Candidate predicate:
     *   status = completed
     *   AND processed_at IS NOT NULL
     *   AND processed_at < NOW() - INTERVAL retention_days DAY
     */
    public function prune(
        bool $dryRun = true,
        ?int $batchSize = null,
        ?int $limit = null,
        ?Carbon $at = null,
    ): RetentionOutboxPruneSummary {
        $at ??= now();
        $batchSize = max(1, $batchSize ?? (int) config('retention.outbox_prune_batch_size', 1000));
        $retentionDays = max(1, (int) config('retention.completed_outbox_days', 14));
        $cutoff = $at->copy()->subDays($retentionDays);

        if (! Schema::hasTable('outbox_events')) {
            return $this->emptySummary($at, $dryRun, $retentionDays, $cutoff, $batchSize);
        }

        $tableTotalCount = OutboxEvent::query()->count();
        $excludedPending = OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count();
        $excludedProcessing = OutboxEvent::query()->where('status', OutboxEventStatus::Processing)->count();
        $excludedFailed = OutboxEvent::query()->where('status', OutboxEventStatus::Failed)->count();
        $excludedRecentCompleted = OutboxEvent::query()
            ->where('status', OutboxEventStatus::Completed)
            ->whereNotNull('processed_at')
            ->where('processed_at', '>=', $cutoff)
            ->count();
        $excludedNullProcessedAt = OutboxEvent::query()
            ->where('status', OutboxEventStatus::Completed)
            ->whereNull('processed_at')
            ->count();

        $candidateQuery = $this->candidateQuery($cutoff);
        $candidateCount = (clone $candidateQuery)->count();
        $candidatesByEventType = (clone $candidateQuery)
            ->selectRaw('event_type, COUNT(*) as aggregate_count')
            ->groupBy('event_type')
            ->orderByDesc('aggregate_count')
            ->pluck('aggregate_count', 'event_type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        if ($dryRun) {
            return new RetentionOutboxPruneSummary(
                inspectedAt: $at,
                dryRun: true,
                retentionDays: $retentionDays,
                cutoffAt: $cutoff->toIso8601String(),
                candidateCount: $candidateCount,
                tableTotalCount: $tableTotalCount,
                candidatesByEventType: $candidatesByEventType,
                excludedPending: $excludedPending,
                excludedProcessing: $excludedProcessing,
                excludedFailed: $excludedFailed,
                excludedRecentCompleted: $excludedRecentCompleted,
                excludedNullProcessedAt: $excludedNullProcessedAt,
                deletedCount: 0,
                batchesProcessed: 0,
                batchSize: $batchSize,
            );
        }

        $deletedCount = 0;
        $batchesProcessed = 0;
        $remainingLimit = $limit !== null ? max(0, $limit) : null;

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

            OutboxEvent::query()->whereIn('id', $ids->all())->delete();

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

        return new RetentionOutboxPruneSummary(
            inspectedAt: $at,
            dryRun: false,
            retentionDays: $retentionDays,
            cutoffAt: $cutoff->toIso8601String(),
            candidateCount: $candidateCount,
            tableTotalCount: $tableTotalCount,
            candidatesByEventType: $candidatesByEventType,
            excludedPending: $excludedPending,
            excludedProcessing: $excludedProcessing,
            excludedFailed: $excludedFailed,
            excludedRecentCompleted: $excludedRecentCompleted,
            excludedNullProcessedAt: $excludedNullProcessedAt,
            deletedCount: $deletedCount,
            batchesProcessed: $batchesProcessed,
            batchSize: $batchSize,
        );
    }

    /**
     * @return Builder<OutboxEvent>
     */
    public function candidateQuery(Carbon $cutoff): Builder
    {
        return OutboxEvent::query()
            ->where('status', OutboxEventStatus::Completed)
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', $cutoff);
    }

    private function emptySummary(
        Carbon $at,
        bool $dryRun,
        int $retentionDays,
        Carbon $cutoff,
        int $batchSize,
    ): RetentionOutboxPruneSummary {
        return new RetentionOutboxPruneSummary(
            inspectedAt: $at,
            dryRun: $dryRun,
            retentionDays: $retentionDays,
            cutoffAt: $cutoff->toIso8601String(),
            candidateCount: 0,
            tableTotalCount: 0,
            candidatesByEventType: [],
            excludedPending: 0,
            excludedProcessing: 0,
            excludedFailed: 0,
            excludedRecentCompleted: 0,
            excludedNullProcessedAt: 0,
            deletedCount: 0,
            batchesProcessed: 0,
            batchSize: $batchSize,
        );
    }
}
