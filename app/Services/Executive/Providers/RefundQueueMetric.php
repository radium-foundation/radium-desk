<?php

namespace App\Services\Executive\Providers;

use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;

class RefundQueueMetric extends AbstractExecutiveMetricProvider
{
    public function id(): string
    {
        return 'refund_queue';
    }

    protected function title(): string
    {
        return 'Refund Queue';
    }

    protected function icon(): string
    {
        return 'bi-cash-coin';
    }

    protected function valueFromContext(ExecutiveMetricsContext $context): int
    {
        return $context->refundQueue;
    }

    protected function statusFor(int $value): PlatformHealthStatus
    {
        if ($value >= 5) {
            return PlatformHealthStatus::Critical;
        }

        if ($value >= 1) {
            return PlatformHealthStatus::Warning;
        }

        return PlatformHealthStatus::Healthy;
    }

    protected function detailUrl(): ?string
    {
        return route('refunds.index', ['status' => 'pending']);
    }
}
