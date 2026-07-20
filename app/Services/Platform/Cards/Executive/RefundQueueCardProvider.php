<?php

namespace App\Services\Platform\Cards\Executive;

class RefundQueueCardProvider extends AbstractExecutiveMetricCardProvider
{
    protected function metricId(): string
    {
        return 'exec_refund_queue';
    }

    protected function metricKey(): string
    {
        return 'refund_queue';
    }

    protected function metricTitle(): string
    {
        return 'Refund Queue';
    }

    protected function metricIcon(): string
    {
        return 'bi-cash-coin';
    }

    protected function priority(): int
    {
        return 30;
    }
}
