<?php

namespace App\Contracts\Timeline;

use App\Data\TimelineEvent;
use Illuminate\Support\Collection;

interface TimelineEventSource
{
    /**
     * @return Collection<int, TimelineEvent>
     */
    public function collect(?int $limit = null): Collection;
}
