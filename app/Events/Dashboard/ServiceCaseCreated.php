<?php

namespace App\Events\Dashboard;

class ServiceCaseCreated extends DashboardBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'ServiceCaseCreated';
    }
}
