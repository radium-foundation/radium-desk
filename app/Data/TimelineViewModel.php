<?php

namespace App\Data;

use Illuminate\Support\Collection;

readonly class TimelineViewModel
{
    /**
     * @param  Collection<int, TimelineDayGroup>  $groups
     */
    public function __construct(
        public Collection $groups,
        public int $totalCount,
        public int $loadedCount,
        public int $offset,
        public int $limit,
        public bool $hasMore,
    ) {}

    public function isEmpty(): bool
    {
        return $this->totalCount === 0;
    }

    /**
     * @return Collection<int, TimelineEvent>
     */
    public function events(): Collection
    {
        return $this->groups->flatMap(fn (TimelineDayGroup $group) => $group->events);
    }
}
