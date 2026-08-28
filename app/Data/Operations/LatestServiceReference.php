<?php

namespace App\Data\Operations;

use Illuminate\Support\Carbon;

readonly class LatestServiceReference
{
    public function __construct(
        public string $serviceReference,
        public string $agentName,
        public Carbon $addedAt,
    ) {}
}
