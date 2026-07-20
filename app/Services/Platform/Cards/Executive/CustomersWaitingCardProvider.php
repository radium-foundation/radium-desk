<?php

namespace App\Services\Platform\Cards\Executive;

class CustomersWaitingCardProvider extends AbstractExecutiveMetricCardProvider
{
    protected function metricId(): string
    {
        return 'exec_waiting_customer';
    }

    protected function metricKey(): string
    {
        return 'customers_waiting';
    }

    protected function metricTitle(): string
    {
        return 'Customers Waiting';
    }

    protected function metricIcon(): string
    {
        return 'bi-hourglass-split';
    }

    protected function priority(): int
    {
        return 50;
    }
}
