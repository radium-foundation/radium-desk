<?php

namespace App\Events\Dashboard;

class ServiceCaseResolved extends DashboardBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'ServiceCaseResolved';
    }
}
