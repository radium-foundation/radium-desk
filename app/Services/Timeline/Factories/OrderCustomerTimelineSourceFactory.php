<?php

namespace App\Services\Timeline\Factories;

use App\Contracts\Timeline\Customer360TimelineSourceFactory;
use App\Contracts\Timeline\TimelineEventSource;
use App\Models\Order;
use App\Services\AutomationIdentityService;
use App\Services\OrderActivityTimelineService;
use App\Services\Timeline\Sources\OrderCustomerTimelineSource;

final class OrderCustomerTimelineSourceFactory implements Customer360TimelineSourceFactory
{
    public function __construct(
        private readonly OrderActivityTimelineService $orderActivityTimelineService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function make(Order $order): TimelineEventSource
    {
        return new OrderCustomerTimelineSource(
            order: $order,
            orderActivityTimelineService: $this->orderActivityTimelineService,
            automationIdentity: $this->automationIdentity,
        );
    }
}
