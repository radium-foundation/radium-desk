<?php

namespace App\Services\Timeline;

use App\Data\TimelineViewModel;
use App\Models\Incident;
use App\Models\Order;
use App\Services\AutomationIdentityService;
use App\Services\OrderActivityTimelineService;
use App\Services\Timeline\Sources\OrderCustomerTimelineSource;
use App\Services\Timeline\Sources\WhatsAppTemplateDispatchTimelineSource;
use App\Services\Timeline\Sources\WhatsAppTimelineEventSource;

class Customer360TimelineService
{
    public function __construct(
        private readonly TimelineService $timelineService,
        private readonly OrderActivityTimelineService $orderActivityTimelineService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function forIncident(Incident $incident, int $offset = 0, ?int $limit = null): TimelineViewModel
    {
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

        return $this->forOrder($order, $offset, $limit);
    }

    public function forOrder(Order $order, int $offset = 0, ?int $limit = null): TimelineViewModel
    {
        $pageSize = $limit ?? TimelineService::DEFAULT_PAGE_SIZE;

        return $this->timelineService->build(
            sources: [
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
            ],
            offset: $offset,
            limit: $pageSize,
        );
    }
}
