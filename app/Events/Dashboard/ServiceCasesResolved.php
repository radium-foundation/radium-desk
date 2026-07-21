<?php

namespace App\Events\Dashboard;

class ServiceCasesResolved extends HybridIncidentsUpdated
{
    public function broadcastAs(): string
    {
        return 'ServiceCasesResolved';
    }
}
