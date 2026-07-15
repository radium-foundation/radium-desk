<?php

namespace App\Services\Timeline;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineDayGroup;
use App\Data\TimelineEvent;
use App\Data\TimelineViewModel;
use App\Enums\TimelineDayBucket;
use App\Support\AppDateFormatter;
use App\Support\Timeline\TimelineGroupResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TimelineService
{
    public const DEFAULT_PAGE_SIZE = 8;

    /**
     * @param  iterable<TimelineEventSource>  $sources
     */
    public function build(
        iterable $sources,
        int $offset = 0,
        int $limit = self::DEFAULT_PAGE_SIZE,
        ?Customer360TimelineRequestCache $cache = null,
        ?int $cacheKey = null,
    ): TimelineViewModel {
        if ($cache !== null && $cacheKey !== null && ($cached = $cache->get($cacheKey)) !== null) {
            return $this->paginate($cached, $offset, $limit);
        }

        $needed = $offset + $limit;
        $partial = $this->mergeSources($sources, $needed);

        if ($partial->count() < $needed) {
            if ($cache !== null && $cacheKey !== null) {
                $cache->put($cacheKey, $partial);
            }

            return $this->paginate($partial, $offset, $limit);
        }

        $events = $this->mergeSources($sources);

        if ($cache !== null && $cacheKey !== null) {
            $cache->put($cacheKey, $events);
        }

        return $this->paginate($events, $offset, $limit);
    }

    /**
     * @param  Collection<int, TimelineEvent>  $events
     */
    public function paginate(Collection $events, int $offset, int $limit): TimelineViewModel
    {
        $totalCount = $events->count();
        $page = $events->slice($offset, $limit)->values();

        return new TimelineViewModel(
            groups: $this->groupByDay($page),
            totalCount: $totalCount,
            loadedCount: $offset + $page->count(),
            offset: $offset,
            limit: $limit,
            hasMore: ($offset + $limit) < $totalCount,
        );
    }

    /**
     * @param  iterable<TimelineEventSource>  $sources
     * @return Collection<int, TimelineEvent>
     */
    public function mergeSources(iterable $sources, ?int $limit = null): Collection
    {
        $events = collect();

        foreach ($sources as $source) {
            $events = $events->merge($source->collect($limit));
        }

        return $events
            ->unique(fn (TimelineEvent $event) => $event->dedupeKey)
            ->sortByDesc(fn (TimelineEvent $event) => [
                $event->occurredAt->timestamp,
                $event->dedupeKey,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, TimelineEvent>  $events
     * @return Collection<int, TimelineDayGroup>
     */
    public function groupByDay(Collection $events): Collection
    {
        if ($events->isEmpty()) {
            return collect();
        }

        $reference = AppDateFormatter::inAppTimezone(now()) ?? now();

        return $events
            ->groupBy(function (TimelineEvent $event) use ($reference): string {
                return TimelineGroupResolver::resolve($event->occurredAt, $reference)['key'];
            })
            ->map(function (Collection $groupEvents, string $groupKey) use ($reference): TimelineDayGroup {
                $firstEvent = $groupEvents->first();
                $resolved = TimelineGroupResolver::resolve($firstEvent->occurredAt, $reference);

                return new TimelineDayGroup(
                    bucket: $this->bucketForGroupKey($groupKey),
                    events: $groupEvents->values(),
                    displayLabel: $resolved['label'],
                    sortKey: $resolved['sort_key'],
                );
            })
            ->sortBy(fn (TimelineDayGroup $group) => $group->sortKey)
            ->values();
    }

    private function bucketForGroupKey(string $groupKey): TimelineDayBucket
    {
        return match (true) {
            $groupKey === 'today' => TimelineDayBucket::Today,
            $groupKey === 'yesterday' => TimelineDayBucket::Yesterday,
            default => TimelineDayBucket::Earlier,
        };
    }
}
