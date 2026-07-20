<?php

namespace App\Services\Platform\Cards\Executive;

class ActiveAgentsCardProvider extends AbstractExecutiveMetricCardProvider
{
    protected function metricId(): string
    {
        return 'exec_active_agents';
    }

    protected function metricKey(): string
    {
        return 'active_agents';
    }

    protected function metricTitle(): string
    {
        return 'Active Agents';
    }

    protected function metricIcon(): string
    {
        return 'bi-people';
    }

    protected function priority(): int
    {
        return 40;
    }
}
