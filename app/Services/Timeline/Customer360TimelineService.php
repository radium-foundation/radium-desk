<?php

namespace App\Services\Timeline;

use App\Data\TimelineViewModel;
use App\Models\Incident;
use App\Models\Order;
use App\Services\AutomationIdentityService;
use App\Services\OrderActivityTimelineService;
use App\Services\Timeline\Sources\AppointmentTimelineEventSource;
use App\Services\Timeline\Sources\BonVoiceCallTimelineEventSource;
use App\Services\Timeline\Sources\NotificationTimelineEventSource;
use App\Services\Timeline\Sources\OrderCustomerTimelineSource;
use App\Services\Timeline\Sources\RadiumBoxSyncTimelineEventSource;
use App\Services\Timeline\Sources\ServiceCaseLifecycleTimelineEventSource;
use App\Services\Timeline\Sources\WhatsAppTemplateDispatchTimelineSource;
use App\Services\Timeline\Sources\WhatsAppTimelineEventSource;

class Customer360TimelineService
{
    public function __construct(
        private readonly TimelineService $timelineService,
        private readonly OrderActivityTimelineService $orderActivityTimelineService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly Customer360TimelineRequestCache $timelineRequestCache,
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

        $sources = $this->sourcesForOrder($order);

        $originOrder = $incident->inquiryOriginOrder;

        if ($originOrder !== null) {
            foreach ($this->sourcesForOrder($originOrder) as $source) {
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
            sources: $this->sourcesForOrder($order),
            offset: $offset,
            limit: $pageSize,
            cache: $this->timelineRequestCache,
            cacheKey: $order->id,
        );
    }

    /**
     * @return list<\App\Contracts\Timeline\TimelineEventSource>
     */
    private function sourcesForOrder(Order $order): array
    {
        return [
            new OrderCustomerTimelineSource(
                order: $order,
                orderActivityTimelineService: $this->orderActivityTimelineService,
                automationIdentity: $this->automationIdentity,
            ),
            app()->makeWith(WhatsAppTimelineEventSource::class, [
                'order' => $order,
            ]),
            new WhatsAppTemplateDispatchTimelineSource(
                order: $order,
            ),
            app()->makeWith(NotificationTimelineEventSource::class, [
                'order' => $order,
            ]),
            app()->makeWith(RadiumBoxSyncTimelineEventSource::class, [
                'order' => $order,
            ]),
            app()->makeWith(AppointmentTimelineEventSource::class, [
                'order' => $order,
            ]),
            app()->makeWith(ServiceCaseLifecycleTimelineEventSource::class, [
                'order' => $order,
            ]),
            app()->makeWith(BonVoiceCallTimelineEventSource::class, [
                'order' => $order,
            ]),
        ];
    }
}
