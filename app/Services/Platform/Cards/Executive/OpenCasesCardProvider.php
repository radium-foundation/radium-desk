<?php

namespace App\Services\Platform\Cards\Executive;

class OpenCasesCardProvider extends AbstractExecutiveMetricCardProvider
{
    protected function metricId(): string
    {
        return 'exec_open_cases';
    }

    protected function metricKey(): string
    {
        return 'open_cases';
    }

    protected function metricTitle(): string
    {
        return 'Open Cases';
    }

    protected function metricIcon(): string
    {
        return 'bi-folder2-open';
    }

    protected function priority(): int
    {
        return 10;
    }
}
