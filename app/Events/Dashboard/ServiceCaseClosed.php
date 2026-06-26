<?php

namespace App\Events\Dashboard;

class ServiceCaseClosed extends DashboardBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'ServiceCaseClosed';
    }
}
