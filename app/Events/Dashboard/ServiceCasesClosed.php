<?php

namespace App\Events\Dashboard;

class ServiceCasesClosed extends HybridIncidentsUpdated
{
    public function broadcastAs(): string
    {
        return 'ServiceCasesClosed';
    }
}
