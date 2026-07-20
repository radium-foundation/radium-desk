<?php

namespace App\Services\Executive\Providers;

use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;

class ActiveAgentsMetric extends AbstractExecutiveMetricProvider
{
    public function id(): string
    {
        return 'active_agents';
    }

    protected function title(): string
    {
        return 'Active Agents';
    }

    protected function icon(): string
    {
        return 'bi-people';
    }

    protected function valueFromContext(ExecutiveMetricsContext $context): int
    {
        return $context->activeAgents;
    }

    protected function statusFor(int $value): PlatformHealthStatus
    {
        return PlatformHealthStatus::Healthy;
    }

    protected function detailUrl(): ?string
    {
        return route('workforce.index');
    }
}
