<?php

namespace App\Services\Operations;

use App\Contracts\Operations\IraReasoningProvider;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalSnapshotData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class IraOperationsBrainService
{
    private const CACHE_KEY = 'ira:operations:briefing';

    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly IraMemoryService $memoryService,
        private readonly IraRiskDetectionService $riskDetectionService,
        private readonly IraRecommendationEngineService $recommendationEngineService,
        private readonly IraReasoningProvider $reasoningProvider,
    ) {}

    public function briefing(?Carbon $at = null, bool $useCache = true): IraMorningBriefing
    {
        $at ??= now();

        if ($useCache) {
            $cached = Cache::get($this->cacheKey($at));

            if ($cached instanceof IraMorningBriefing) {
                return $cached;
            }
        }

        $briefing = $this->buildBriefing($at);

        Cache::put($this->cacheKey($at), $briefing, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $briefing;
    }

    public function currentSnapshot(?Carbon $at = null): IraOperationalSnapshotData
    {
        $at ??= now();
        $this->memoryService->ensureTodaySnapshot($at);

        return $this->memoryService->collectSnapshotData($at);
    }

    public function reasoningProviderName(): string
    {
        return $this->reasoningProvider->name();
    }

    public function invalidateCache(?Carbon $at = null): void
    {
        Cache::forget($this->cacheKey($at ?? now()));
        $this->memoryService->invalidateSnapshotDataCache($at);
    }

    private function buildBriefing(Carbon $at): IraMorningBriefing
    {
        $memorySnapshot = $this->memoryService->capture($at);
        $snapshot = IraOperationalSnapshotData::fromModel($memorySnapshot);
        $yesterdayModel = $this->memoryService->yesterdaySnapshot($at);
        $yesterday = $yesterdayModel !== null
            ? IraOperationalSnapshotData::fromModel($yesterdayModel)
            : null;

        $risks = $this->riskDetectionService->detect($snapshot, $at);
        $recommendations = $this->recommendationEngineService->recommend($snapshot, $risks, $at);

        return $this->reasoningProvider->generateBriefing(
            $snapshot,
            $yesterday,
            $risks,
            $recommendations,
            $at,
        );
    }

    private function cacheKey(Carbon $at): string
    {
        return self::CACHE_KEY.':'.$at->toDateString();
    }
}
