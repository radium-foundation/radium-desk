<?php

namespace App\Events\Dashboard;

class ServiceCasesAssigned extends HybridIncidentsUpdated
{
    public function broadcastAs(): string
    {
        return 'ServiceCasesAssigned';
    }
}
