<?php

namespace App\Services\Executive\Providers;

use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;

class OpenCasesMetric extends AbstractExecutiveMetricProvider
{
    public function id(): string
    {
        return 'open_cases';
    }

    protected function title(): string
    {
        return 'Open Cases';
    }

    protected function icon(): string
    {
        return 'bi-folder2-open';
    }

    protected function valueFromContext(ExecutiveMetricsContext $context): int
    {
        return $context->openCases;
    }

    protected function statusFor(int $value): PlatformHealthStatus
    {
        return PlatformHealthStatus::Healthy;
    }

    protected function detailUrl(): ?string
    {
        return route('incidents.index', ['status' => 'open']);
    }
}
