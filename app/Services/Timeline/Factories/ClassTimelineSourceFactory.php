<?php

namespace App\Services\Timeline\Factories;

use App\Contracts\Timeline\Customer360TimelineSourceFactory;
use App\Contracts\Timeline\TimelineEventSource;
use App\Models\Order;
use Illuminate\Contracts\Container\Container;

final class ClassTimelineSourceFactory implements Customer360TimelineSourceFactory
{
    public function __construct(
        private readonly Container $container,
        private readonly string $sourceClass,
    ) {}

    public function make(Order $order): TimelineEventSource
    {
        return $this->container->makeWith($this->sourceClass, [
            'order' => $order,
        ]);
    }
}
