<?php

namespace App\Data\Executive;

use Illuminate\Support\Carbon;

readonly class ExecutiveMetricsContext
{
    public function __construct(
        public ExecutiveMetricPeriod $period,
        public Carbon $dayStart,
        public Carbon $dayEnd,
        public int $openCases,
        public int $criticalCases,
        public int $activeAgents,
        public int $customersWaiting,
        public int $refundQueue,
        public int $ordersToday,
        public int $resolvedToday,
        public int $appointmentsToday,
        public Carbon $computedAt,
    ) {}
}
