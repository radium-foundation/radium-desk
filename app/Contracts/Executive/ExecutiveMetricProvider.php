<?php

namespace App\Contracts\Executive;

use App\Data\Executive\ExecutiveMetricDto;
use App\Data\Executive\ExecutiveMetricsContext;

interface ExecutiveMetricProvider
{
    public function id(): string;

    public function fromContext(ExecutiveMetricsContext $context): ExecutiveMetricDto;
}
