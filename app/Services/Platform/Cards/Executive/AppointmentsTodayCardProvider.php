<?php

namespace App\Services\Platform\Cards\Executive;

class AppointmentsTodayCardProvider extends AbstractExecutiveMetricCardProvider
{
    protected function metricId(): string
    {
        return 'exec_appointments_today';
    }

    protected function metricKey(): string
    {
        return 'appointments_today';
    }

    protected function metricTitle(): string
    {
        return 'Appointments Today';
    }

    protected function metricIcon(): string
    {
        return 'bi-calendar-event';
    }

    protected function priority(): int
    {
        return 80;
    }
}
