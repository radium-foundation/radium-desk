<?php

namespace App\Data;

readonly class TeamActivityPendingMetrics
{
    public function __construct(
        public int $pendingCount,
        public int $overdueCount,
    ) {}
}
