<?php

namespace App\Data;

use App\Enums\TimelineDayBucket;
use Illuminate\Support\Collection;

readonly class TimelineDayGroup
{
    /**
     * @param  Collection<int, TimelineEvent>  $events
     */
    public function __construct(
        public TimelineDayBucket $bucket,
        public Collection $events,
        public ?string $displayLabel = null,
        public int $sortKey = 0,
    ) {}

    public function label(): string
    {
        return $this->displayLabel ?? $this->bucket->label();
    }
}
