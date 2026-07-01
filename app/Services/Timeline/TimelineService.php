<?php

namespace App\Services\Timeline;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineDayGroup;
use App\Data\TimelineEvent;
use App\Data\TimelineViewModel;
use App\Enums\TimelineDayBucket;
use App\Support\AppDateFormatter;
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
    ): TimelineViewModel {
        $events = $this->mergeSources($sources);
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
    public function mergeSources(iterable $sources): Collection
    {
        $events = collect();

        foreach ($sources as $source) {
            $events = $events->merge($source->collect());
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

        $today = $this->dayStart(now());
        $yesterday = $today->copy()->subDay();

        return $events
            ->groupBy(function (TimelineEvent $event) use ($today, $yesterday): string {
                $eventDay = $this->dayStart($event->occurredAt);

                if ($eventDay->equalTo($today)) {
                    return TimelineDayBucket::Today->value;
                }

                if ($eventDay->equalTo($yesterday)) {
                    return TimelineDayBucket::Yesterday->value;
                }

                return TimelineDayBucket::Earlier->value;
            })
            ->map(function (Collection $groupEvents, string $bucketValue): TimelineDayGroup {
                return new TimelineDayGroup(
                    bucket: TimelineDayBucket::from($bucketValue),
                    events: $groupEvents->values(),
                );
            })
            ->sortBy(fn (TimelineDayGroup $group) => $group->bucket->sortOrder())
            ->values();
    }

    private function dayStart(Carbon $date): Carbon
    {
        return AppDateFormatter::inAppTimezone($date)?->startOfDay() ?? $date->copy()->startOfDay();
    }
}
