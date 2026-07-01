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
    ) {}

    public function label(): string
    {
        return $this->bucket->label();
    }
}
