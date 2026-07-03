<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Models\Order;
use App\Services\Timeline\Mappers\RadiumBoxSyncTimelineEventMapper;
use Illuminate\Support\Collection;

class RadiumBoxSyncTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly RadiumBoxSyncTimelineEventMapper $mapper,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $events = $this->mapper->forOrder($this->order);

        if ($limit !== null) {
            return $events->take($limit)->values();
        }

        return $events;
    }
}
