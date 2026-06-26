<?php

namespace App\Events\Dashboard;

class SlaStatusChanged extends DashboardBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'SlaStatusChanged';
    }
}
