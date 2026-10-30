<?php

namespace App\Data\StatutoryCoverage;

use Illuminate\Support\Carbon;

final readonly class StatutoryCoverageWindow
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public string $timezone,
    ) {}

    public function toArray(): array
    {
        return [
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'timezone' => $this->timezone,
        ];
    }
}
