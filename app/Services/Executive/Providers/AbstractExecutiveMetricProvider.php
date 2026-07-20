<?php

namespace App\Services\Executive\Providers;

use App\Contracts\Executive\ExecutiveMetricProvider;
use App\Data\Executive\ExecutiveMetricDto;
use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;

abstract class AbstractExecutiveMetricProvider implements ExecutiveMetricProvider
{
    abstract public function id(): string;

    abstract protected function title(): string;

    abstract protected function icon(): string;

    abstract protected function valueFromContext(ExecutiveMetricsContext $context): int;

    abstract protected function statusFor(int $value): PlatformHealthStatus;

    abstract protected function detailUrl(): ?string;

    protected function subtitle(): ?string
    {
        return null;
    }

    public function fromContext(ExecutiveMetricsContext $context): ExecutiveMetricDto
    {
        $value = $this->valueFromContext($context);

        return new ExecutiveMetricDto(
            id: $this->id(),
            title: $this->title(),
            value: $value,
            formattedValue: number_format($value),
            status: $this->statusFor($value),
            subtitle: $this->subtitle(),
            lastUpdated: $context->computedAt->copy(),
            detailUrl: $this->detailUrl(),
            icon: $this->icon(),
            period: $context->period,
            currentValue: $value,
        );
    }
}
