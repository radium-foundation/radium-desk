<?php

namespace App\Services\Executive\Providers;

use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;

class AppointmentsTodayMetric extends AbstractExecutiveMetricProvider
{
    public function id(): string
    {
        return 'appointments_today';
    }

    protected function title(): string
    {
        return 'Appointments Today';
    }

    protected function icon(): string
    {
        return 'bi-calendar-event';
    }

    protected function valueFromContext(ExecutiveMetricsContext $context): int
    {
        return $context->appointmentsToday;
    }

    protected function statusFor(int $value): PlatformHealthStatus
    {
        return PlatformHealthStatus::Healthy;
    }

    protected function detailUrl(): ?string
    {
        return route('admin.operations.index');
    }
}
