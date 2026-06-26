<?php

namespace App\Events\Dashboard;

class TransactionAssigned extends DashboardBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'TransactionAssigned';
    }
}
