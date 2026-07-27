<?php

namespace App\Services\Timeline;

use App\Data\Timeline\BusinessTimelineViewModel;
use App\Data\TimelineEvent;
use App\Data\TimelineViewModel;
use App\Models\Incident;
use App\Models\Order;
use Illuminate\Support\Collection;

class Customer360TimelineService
{
    public function __construct(
        private readonly TimelineService $timelineService,
        private readonly Customer360TimelineRequestCache $timelineRequestCache,
        private readonly Customer360TimelineSourceRegistry $sourceRegistry,
        private readonly Customer360OperatorTimelinePresentation $operatorPresentation,
        private readonly BusinessTimelineComposer $businessTimelineComposer,
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

        $sources = $this->sourcesForIncident($incident);

        return $this->buildOperatorView(
            sources: $sources,
            order: $order,
            offset: $offset,
            limit: $limit,
            cacheKey: $incident->inquiryOriginOrder === null ? $order->id : null,
        );
    }

    public function forOrder(Order $order, int $offset = 0, ?int $limit = null): TimelineViewModel
    {
        return $this->buildOperatorView(
            sources: $this->sourceRegistry->sourcesForOrder($order),
            order: $order,
            offset: $offset,
            limit: $limit,
            cacheKey: $order->id,
        );
    }

    /**
     * Timeline-tab Business Timeline presentation. Does not affect forOrder()/Intelligence.
     */
    public function businessForIncident(
        Incident $incident,
        int $offset = 0,
        ?int $limit = null,
        ?string $query = null,
    ): BusinessTimelineViewModel {
        $pageSize = $limit ?? TimelineService::DEFAULT_PAGE_SIZE;
        $events = $this->operatorVisibleEventsForIncident($incident);

        return $this->businessTimelineComposer->compose($events, $offset, $pageSize, $query);
    }

    /**
     * @return Collection<int, TimelineEvent>
     */
    public function operatorVisibleEventsForIncident(Incident $incident): Collection
    {
        $incident->loadMissing(['order', 'inquiryOriginOrder']);
        $order = $incident->order;

        if ($order === null) {
            return collect();
        }

        $sources = $this->sourcesForIncident($incident);
        $cacheKey = $incident->inquiryOriginOrder === null ? $order->id : null;
        $rawEvents = $cacheKey !== null
            ? $this->timelineRequestCache->get($cacheKey)
            : null;

        if ($rawEvents === null) {
            $rawEvents = $this->timelineService->mergeSources($sources);

            if ($cacheKey !== null) {
                $this->timelineRequestCache->put($cacheKey, $rawEvents);
            }
        }

        return $this->operatorPresentation->apply($rawEvents, $order);
    }

    /**
     * @return list<\App\Contracts\Timeline\TimelineEventSource>
     */
    private function sourcesForIncident(Incident $incident): array
    {
        $order = $incident->order;
        if ($order === null) {
            return [];
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

        return $sources;
    }

    /**
     * @param  list<\App\Contracts\Timeline\TimelineEventSource>  $sources
     */
    private function buildOperatorView(
        array $sources,
        Order $order,
        int $offset,
        ?int $limit,
        ?int $cacheKey,
    ): TimelineViewModel {
        $pageSize = $limit ?? TimelineService::DEFAULT_PAGE_SIZE;
        $rawEvents = $cacheKey !== null
            ? $this->timelineRequestCache->get($cacheKey)
            : null;

        if ($rawEvents === null) {
            $rawEvents = $this->timelineService->mergeSources($sources);

            if ($cacheKey !== null) {
                $this->timelineRequestCache->put($cacheKey, $rawEvents);
            }
        }

        $operatorEvents = $this->operatorPresentation->apply($rawEvents, $order);

        return $this->timelineService->paginate($operatorEvents, $offset, $pageSize);
    }
}
