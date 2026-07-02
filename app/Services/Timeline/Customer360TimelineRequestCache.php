<?php

namespace App\Services\Timeline;

use App\Data\TimelineEvent;
use Illuminate\Support\Collection;

/**
 * Request-scoped cache for merged Customer 360 timeline events.
 *
 * Prevents rebuilding the full merged timeline when paginating or when multiple
 * services request the same order timeline during one HTTP request.
 */
class Customer360TimelineRequestCache
{
    /** @var array<int, Collection<int, TimelineEvent>> */
    private array $mergedEvents = [];

    /**
     * @return Collection<int, TimelineEvent>
     */
    public function remember(int $orderId, callable $resolver): Collection
    {
        if (isset($this->mergedEvents[$orderId])) {
            return $this->mergedEvents[$orderId];
        }

        return $this->mergedEvents[$orderId] = $resolver();
    }

    /**
     * @param  Collection<int, TimelineEvent>  $events
     */
    public function put(int $orderId, Collection $events): void
    {
        $this->mergedEvents[$orderId] = $events;
    }

    /**
     * @return Collection<int, TimelineEvent>|null
     */
    public function get(int $orderId): ?Collection
    {
        return $this->mergedEvents[$orderId] ?? null;
    }
}
