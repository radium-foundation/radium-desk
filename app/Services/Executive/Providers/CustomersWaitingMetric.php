<?php

namespace App\Services\Executive\Providers;

use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;

class CustomersWaitingMetric extends AbstractExecutiveMetricProvider
{
    public function id(): string
    {
        return 'customers_waiting';
    }

    protected function title(): string
    {
        return 'Customers Waiting';
    }

    protected function icon(): string
    {
        return 'bi-hourglass-split';
    }

    protected function valueFromContext(ExecutiveMetricsContext $context): int
    {
        return $context->customersWaiting;
    }

    protected function statusFor(int $value): PlatformHealthStatus
    {
        if ($value >= 10) {
            return PlatformHealthStatus::Critical;
        }

        if ($value >= 4) {
            return PlatformHealthStatus::Warning;
        }

        return PlatformHealthStatus::Healthy;
    }

    protected function detailUrl(): ?string
    {
        return route('dashboard', ['queue' => 'waiting_customer']);
    }
}
