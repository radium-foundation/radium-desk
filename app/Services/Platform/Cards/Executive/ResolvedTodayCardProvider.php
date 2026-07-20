<?php

namespace App\Services\Platform\Cards\Executive;

class ResolvedTodayCardProvider extends AbstractExecutiveMetricCardProvider
{
    protected function metricId(): string
    {
        return 'exec_resolved_today';
    }

    protected function metricKey(): string
    {
        return 'resolved_today';
    }

    protected function metricTitle(): string
    {
        return 'Resolved Today';
    }

    protected function metricIcon(): string
    {
        return 'bi-check2-circle';
    }

    protected function priority(): int
    {
        return 70;
    }
}
