<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionCachePruneSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RetentionCachePruneService
{
    /**
     * Prune expired Laravel database cache rows.
     *
     * Candidate predicate: expiration < UNIX_TIMESTAMP(NOW())
     * Active rows (expiration >= now) are never deleted.
     */
    public function prune(
        bool $dryRun = true,
        ?int $batchSize = null,
        ?int $limit = null,
        ?Carbon $at = null,
    ): RetentionCachePruneSummary {
        $at ??= now();
        $batchSize = max(1, $batchSize ?? (int) config('retention.cache_prune_batch_size', 500));
        $nowTimestamp = $at->getTimestamp();

        if (! Schema::hasTable('cache') || ! (bool) config('retention.expired_cache_immediate', true)) {
            return new RetentionCachePruneSummary(
                inspectedAt: $at,
                dryRun: $dryRun,
                candidateCount: 0,
                activeCount: 0,
                tableTotalCount: 0,
                estimatedCandidatePayloadBytes: 0,
                deletedCount: 0,
                batchesProcessed: 0,
                batchSize: $batchSize,
            );
        }

        $candidateCount = (int) DB::table('cache')
            ->where('expiration', '<', $nowTimestamp)
            ->count();

        $activeCount = (int) DB::table('cache')
            ->where('expiration', '>=', $nowTimestamp)
            ->count();

        $tableTotalCount = (int) DB::table('cache')->count();

        $estimatedCandidatePayloadBytes = (int) DB::table('cache')
            ->where('expiration', '<', $nowTimestamp)
            ->sum(DB::raw('LENGTH(COALESCE(value, ""))'));

        if ($dryRun) {
            return new RetentionCachePruneSummary(
                inspectedAt: $at,
                dryRun: true,
                candidateCount: $candidateCount,
                activeCount: $activeCount,
                tableTotalCount: $tableTotalCount,
                estimatedCandidatePayloadBytes: $estimatedCandidatePayloadBytes,
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

            $keys = DB::table('cache')
                ->where('expiration', '<', $nowTimestamp)
                ->orderBy('key')
                ->limit($currentBatchSize)
                ->pluck('key');

            if ($keys->isEmpty()) {
                break;
            }

            DB::table('cache')->whereIn('key', $keys->all())->delete();

            $batchDeleted = $keys->count();
            $deletedCount += $batchDeleted;
            $batchesProcessed++;

            if ($remainingLimit !== null) {
                $remainingLimit -= $batchDeleted;
            }

            if ($batchDeleted < $currentBatchSize) {
                break;
            }
        }

        return new RetentionCachePruneSummary(
            inspectedAt: $at,
            dryRun: false,
            candidateCount: $candidateCount,
            activeCount: $activeCount,
            tableTotalCount: $tableTotalCount,
            estimatedCandidatePayloadBytes: $estimatedCandidatePayloadBytes,
            deletedCount: $deletedCount,
            batchesProcessed: $batchesProcessed,
            batchSize: $batchSize,
        );
    }
}
