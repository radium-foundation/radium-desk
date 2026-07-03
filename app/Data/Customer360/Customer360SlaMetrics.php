<?php

namespace App\Data\Customer360;

readonly class Customer360SlaMetrics
{
    /**
     * @param  array<string, array{median_minutes: ?float, average_minutes: ?float, p95_minutes: ?float, sample_size: int}>  $stages
     */
    public function __construct(
        public array $stages,
    ) {}
}
