<?php

namespace App\Services\Timeline;

use App\Data\TimelineViewModel;
use App\Models\Incident;
use App\Models\Order;

class Customer360TimelineService
{
    public function __construct(
        private readonly TimelineService $timelineService,
        private readonly Customer360TimelineRequestCache $timelineRequestCache,
        private readonly Customer360TimelineSourceRegistry $sourceRegistry,
    ) {}

    public function forIncident(Incident $incident, int $offset = 0, ?int $limit = null): TimelineViewModel
    {
        $incident->loadMissing(['order', 'inquiryOriginOrder']);
        $order = $incident->order;

        if ($order === null) {
            return new TimelineViewModel(
                groups: collect(),
                totalCount: 0,
                loadedCount: 0,
                offset: $offset,
                limit: $limit ?? TimelineService::DEFAULT_PAGE_SIZE,
                hasMore: false,
            );
        }

        $sources = $this->sourceRegistry->sourcesForOrder($order);

        $originOrder = $incident->inquiryOriginOrder;

        if ($originOrder !== null) {
            foreach ($this->sourceRegistry->sourcesForOrder($originOrder) as $source) {
                $sources[] = new PrefixedTimelineEventSource(
                    source: $source,
                    prefix: "inquiry-origin:{$originOrder->id}:",
                );
            }
        }

        $pageSize = $limit ?? TimelineService::DEFAULT_PAGE_SIZE;
        $useCache = $originOrder === null;

        return $this->timelineService->build(
            sources: $sources,
            offset: $offset,
            limit: $pageSize,
            cache: $useCache ? $this->timelineRequestCache : null,
            cacheKey: $useCache ? $order->id : null,
        );
    }

    public function forOrder(Order $order, int $offset = 0, ?int $limit = null): TimelineViewModel
    {
        $pageSize = $limit ?? TimelineService::DEFAULT_PAGE_SIZE;

        return $this->timelineService->build(
            sources: $this->sourceRegistry->sourcesForOrder($order),
            offset: $offset,
            limit: $pageSize,
            cache: $this->timelineRequestCache,
            cacheKey: $order->id,
        );
    }
}
