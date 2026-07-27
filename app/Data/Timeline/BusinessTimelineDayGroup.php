<?php

namespace App\Data\Timeline;

use App\Enums\TimelineDayBucket;
use Illuminate\Support\Collection;

readonly class BusinessTimelineDayGroup
{
    /**
     * @param  Collection<int, BusinessTimelineItem>  $items
     */
    public function __construct(
        public TimelineDayBucket $bucket,
        public Collection $items,
        public ?string $displayLabel = null,
        public int $sortKey = 0,
    ) {}

    public function label(): string
    {
        return $this->displayLabel ?? $this->bucket->label();
    }
}
