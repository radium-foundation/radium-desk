<?php

namespace App\Services\Platform\Cards\Executive;

class CriticalCasesCardProvider extends AbstractExecutiveMetricCardProvider
{
    protected function metricId(): string
    {
        return 'exec_critical_cases';
    }

    protected function metricKey(): string
    {
        return 'critical_cases';
    }

    protected function metricTitle(): string
    {
        return 'Critical Cases';
    }

    protected function metricIcon(): string
    {
        return 'bi-exclamation-triangle';
    }

    protected function priority(): int
    {
        return 20;
    }
}
