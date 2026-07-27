<?php

namespace App\Data\Timeline;

use App\Data\TimelineEvent;
use App\Enums\BusinessMilestoneType;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;

readonly class BusinessTimelineItem
{
    /**
     * @param  list<TimelineEvent>  $rawEvents
     * @param  list<string>  $searchText
     * @param  list<string>  $filterTags
     */
    public function __construct(
        public string $id,
        public BusinessMilestoneType $type,
        public Carbon $occurredAt,
        public string $title,
        public string $summary,
        public int $rawCount,
        public array $rawEvents,
        public array $searchText,
        public array $filterTags,
        public bool $isCluster = false,
    ) {}

    public function matchesFilter(string $filterKey): bool
    {
        if ($filterKey === 'all') {
            return true;
        }

        return in_array($filterKey, $this->filterTags, true);
    }

    public function matchesQuery(string $query): bool
    {
        $needle = mb_strtolower(trim($query));

        if ($needle === '') {
            return true;
        }

        foreach ($this->searchText as $token) {
            if (str_contains(mb_strtolower($token), $needle)) {
                return true;
            }
        }

        return false;
    }

    public function timeLabel(): string
    {
        $today = AppDateFormatter::inAppTimezone(now())?->startOfDay();
        $eventDay = AppDateFormatter::inAppTimezone($this->occurredAt)?->startOfDay();

        if ($today !== null && $eventDay !== null && $eventDay->equalTo($today)) {
            return AppDateFormatter::format($this->occurredAt, 'h:i A') ?? $this->occurredAt->format('h:i A');
        }

        return AppDateFormatter::format($this->occurredAt, 'd M') ?? $this->occurredAt->format('d M');
    }
}
