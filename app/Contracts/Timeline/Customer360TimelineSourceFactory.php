<?php

namespace App\Contracts\Timeline;

use App\Models\Order;

interface Customer360TimelineSourceFactory
{
    public function make(Order $order): TimelineEventSource;
}
