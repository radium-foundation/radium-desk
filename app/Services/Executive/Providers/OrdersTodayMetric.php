<?php

namespace App\Services\Executive\Providers;

use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;

class OrdersTodayMetric extends AbstractExecutiveMetricProvider
{
    public function id(): string
    {
        return 'orders_today';
    }

    protected function title(): string
    {
        return 'Orders Today';
    }

    protected function icon(): string
    {
        return 'bi-bag-check';
    }

    protected function valueFromContext(ExecutiveMetricsContext $context): int
    {
        return $context->ordersToday;
    }

    protected function statusFor(int $value): PlatformHealthStatus
    {
        return PlatformHealthStatus::Healthy;
    }

    protected function detailUrl(): ?string
    {
        return route('orders.index');
    }
}
