<?php

namespace App\Services\Timeline;

use App\Contracts\Timeline\Customer360TimelineSourceFactory;
use App\Contracts\Timeline\TimelineEventSource;
use App\Models\Order;

class Customer360TimelineSourceRegistry
{
    /**
     * @param  list<Customer360TimelineSourceFactory>  $factories
     */
    public function __construct(
        private readonly array $factories,
    ) {}

    /**
     * @return list<TimelineEventSource>
     */
    public function sourcesForOrder(Order $order): array
    {
        return array_map(
            fn (Customer360TimelineSourceFactory $factory): TimelineEventSource => $factory->make($order),
            $this->factories,
        );
    }
}
