<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class OrderTimelineEntry
{
    public function __construct(
        public Carbon $occurredAt,
        public string $title,
        public ?string $detail,
        public ?string $actorName,
        public string $dedupeKey,
    ) {}
}
