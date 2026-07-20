<?php

namespace App\Services\Platform\Cards\Executive;

class OrdersTodayCardProvider extends AbstractExecutiveMetricCardProvider
{
    protected function metricId(): string
    {
        return 'exec_orders_today';
    }

    protected function metricKey(): string
    {
        return 'orders_today';
    }

    protected function metricTitle(): string
    {
        return 'Orders Today';
    }

    protected function metricIcon(): string
    {
        return 'bi-bag-check';
    }

    protected function priority(): int
    {
        return 60;
    }
}
