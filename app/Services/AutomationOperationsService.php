<?php

namespace App\Services;

use App\Data\AutomationOperationsDashboardData;

class AutomationOperationsService
{
    public function __construct(
        private readonly AutomationOperationsSnapshotService $snapshotService,
    ) {}

    public function dashboardData(): AutomationOperationsDashboardData
    {
        return $this->snapshotService->get();
    }
}
