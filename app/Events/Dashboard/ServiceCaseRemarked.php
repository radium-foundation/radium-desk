<?php

namespace App\Events\Dashboard;

class ServiceCaseRemarked extends DashboardBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'ServiceCaseRemarked';
    }
}
