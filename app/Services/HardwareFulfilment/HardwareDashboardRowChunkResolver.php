<?php

namespace App\Services\HardwareFulfilment;

use Illuminate\Http\Request;

/**
 * Within-page row chunks for progressive hardware table rendering (bounded SQL LIMIT/OFFSET).
 */
final class HardwareDashboardRowChunkResolver
{
    /**
     * @return array{
     *     offset_override: int|null,
     *     limit_override: int|null,
     *     chunk_offset: int,
     *     chunk_limit: int|null
     * }
     */
    public function sqlSlice(Request $request, int $pageOffset, int $pageLimit): array
    {
        if (! $request->boolean('hw_progressive') || ! $request->filled('hw_chunk_limit')) {
            return [
                'offset_override' => null,
                'limit_override' => null,
                'chunk_offset' => 0,
                'chunk_limit' => null,
            ];
        }

        $chunkOffset = max(0, (int) $request->integer('hw_chunk_offset', 0));
        $chunkLimit = max(1, (int) $request->integer('hw_chunk_limit', $this->firstChunkSize()));

        return [
            'offset_override' => $pageOffset + $chunkOffset,
            'limit_override' => $chunkLimit,
            'chunk_offset' => $chunkOffset,
            'chunk_limit' => $chunkLimit,
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     offset: int,
     *     limit: int,
     *     page_row_total: int,
     *     chunk_row_count: int,
     *     complete: bool,
     *     next_offset: int
     * }
     */
    public function buildMeta(
        Request $request,
        int $pageOffset,
        int $pageLimit,
        int $unfilteredTotal,
        int $chunkRowCount,
        int $chunkOffset,
        ?int $chunkLimit,
    ): array {
        if ($chunkLimit === null) {
            return [
                'enabled' => false,
                'offset' => 0,
                'limit' => $pageLimit,
                'page_row_total' => $chunkRowCount,
                'chunk_row_count' => $chunkRowCount,
                'complete' => true,
                'next_offset' => $chunkRowCount,
            ];
        }

        $remainingOnPage = max(0, min($pageLimit, $unfilteredTotal - $pageOffset) - $chunkOffset);
        $complete = $chunkRowCount < $chunkLimit
            || ($chunkOffset + $chunkRowCount) >= $pageLimit
            || ($chunkOffset + $chunkRowCount) >= ($unfilteredTotal - $pageOffset);

        return [
            'enabled' => true,
            'offset' => $chunkOffset,
            'limit' => $chunkLimit,
            'page_row_total' => min($pageLimit, max(0, $unfilteredTotal - $pageOffset)),
            'chunk_row_count' => $chunkRowCount,
            'complete' => $complete,
            'next_offset' => min($chunkOffset + $chunkRowCount, $pageLimit),
        ];
    }

    public function firstChunkSize(): int
    {
        return max(1, (int) config('dashboard.hardware_rows_first_chunk', 4));
    }

    public function followUpChunkSize(): int
    {
        return max(1, (int) config('dashboard.hardware_rows_chunk_size', 3));
    }

    public function skipCounts(Request $request): bool
    {
        return $request->boolean('hw_skip_counts');
    }
}
