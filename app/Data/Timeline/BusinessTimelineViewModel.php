<?php

namespace App\Data\Timeline;

use Illuminate\Support\Collection;

readonly class BusinessTimelineViewModel
{
    /**
     * @param  Collection<int, BusinessTimelineDayGroup>  $groups
     */
    public function __construct(
        public Collection $groups,
        public int $totalCount,
        public int $rawEventCount,
        public int $loadedCount,
        public int $offset,
        public int $limit,
        public bool $hasMore,
        public ?string $query = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->totalCount === 0;
    }

    /**
     * @return Collection<int, BusinessTimelineItem>
     */
    public function items(): Collection
    {
        return $this->groups->flatMap(fn (BusinessTimelineDayGroup $group) => $group->items);
    }
}
