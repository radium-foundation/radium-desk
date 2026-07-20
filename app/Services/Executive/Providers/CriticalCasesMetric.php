<?php

namespace App\Services\Executive\Providers;

use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;

class CriticalCasesMetric extends AbstractExecutiveMetricProvider
{
    public function id(): string
    {
        return 'critical_cases';
    }

    protected function title(): string
    {
        return 'Critical Cases';
    }

    protected function icon(): string
    {
        return 'bi-exclamation-triangle';
    }

    protected function valueFromContext(ExecutiveMetricsContext $context): int
    {
        return $context->criticalCases;
    }

    protected function statusFor(int $value): PlatformHealthStatus
    {
        if ($value >= 3) {
            return PlatformHealthStatus::Critical;
        }

        if ($value >= 1) {
            return PlatformHealthStatus::Warning;
        }

        return PlatformHealthStatus::Healthy;
    }

    protected function detailUrl(): ?string
    {
        return route('incidents.index', ['high_priority' => 1]);
    }
}
