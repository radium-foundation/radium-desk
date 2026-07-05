<?php

namespace App\Data\Operations;

use App\Enums\PerformancePeriod;
use Illuminate\Support\Carbon;

readonly class PerformancePeriodRange
{
    public function __construct(
        public PerformancePeriod $period,
        public Carbon $start,
        public Carbon $end,
    ) {}

    public function label(): string
    {
        if ($this->period === PerformancePeriod::Custom) {
            return $this->start->format('M j').' – '.$this->end->format('M j, Y');
        }

        return $this->period->label();
    }
}
