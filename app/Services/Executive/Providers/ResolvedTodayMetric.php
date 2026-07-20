<?php

namespace App\Services\Executive\Providers;

use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;

class ResolvedTodayMetric extends AbstractExecutiveMetricProvider
{
    public function id(): string
    {
        return 'resolved_today';
    }

    protected function title(): string
    {
        return 'Resolved Today';
    }

    protected function icon(): string
    {
        return 'bi-check2-circle';
    }

    protected function valueFromContext(ExecutiveMetricsContext $context): int
    {
        return $context->resolvedToday;
    }

    protected function statusFor(int $value): PlatformHealthStatus
    {
        return PlatformHealthStatus::Healthy;
    }

    protected function detailUrl(): ?string
    {
        return route('incidents.index', ['status' => 'resolved']);
    }
}
