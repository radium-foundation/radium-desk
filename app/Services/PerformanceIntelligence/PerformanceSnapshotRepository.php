<?php

namespace App\Services\PerformanceIntelligence;

use App\Data\PerformanceIntelligence\PerformanceScoreResult;
use App\Models\PerformanceIntelligenceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PerformanceSnapshotRepository
{
    public function upsert(PerformanceScoreResult $result): PerformanceIntelligenceSnapshot
    {
        // whereDate avoids SQLite mismatches between `Y-m-d` and `Y-m-d 00:00:00`.
        $existing = PerformanceIntelligenceSnapshot::query()
            ->where('user_id', $result->userId)
            ->whereDate('snapshot_date', $result->workDate)
            ->first();

        $attributes = $result->toSnapshotAttributes();

        if ($existing !== null) {
            unset($attributes['user_id'], $attributes['snapshot_date']);
            $existing->fill($attributes);
            $existing->save();

            return $existing->refresh();
        }

        return PerformanceIntelligenceSnapshot::query()->create($attributes);
    }

    /**
     * @return Collection<int, PerformanceIntelligenceSnapshot>
     */
    public function forDate(Carbon $date): Collection
    {
        return PerformanceIntelligenceSnapshot::query()
            ->with(['user:id,name,email'])
            ->whereDate('snapshot_date', $date->toDateString())
            ->orderByDesc('composite_score')
            ->orderBy('user_id')
            ->get();
    }

    public function findForUserOnDate(int $userId, Carbon $date): ?PerformanceIntelligenceSnapshot
    {
        return PerformanceIntelligenceSnapshot::query()
            ->with(['user:id,name,email'])
            ->where('user_id', $userId)
            ->whereDate('snapshot_date', $date->toDateString())
            ->first();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, PerformanceIntelligenceSnapshot>
     */
    public function forUsersOnDate(array $userIds, Carbon $date): array
    {
        if ($userIds === []) {
            return [];
        }

        return PerformanceIntelligenceSnapshot::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('snapshot_date', $date->toDateString())
            ->get()
            ->keyBy('user_id')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function availableDates(int $limit = 60): array
    {
        return PerformanceIntelligenceSnapshot::query()
            ->selectRaw('snapshot_date')
            ->distinct()
            ->orderByDesc('snapshot_date')
            ->limit($limit)
            ->pluck('snapshot_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->all();
    }
}
