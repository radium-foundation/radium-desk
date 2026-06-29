<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class OrderTimelineEntry
{
    /**
     * @param  list<OrderCorrectionChange>  $correctionChanges
     */
    public function __construct(
        public Carbon $occurredAt,
        public string $title,
        public ?string $detail,
        public TimelineActor $actor,
        public string $dedupeKey,
        public array $correctionChanges = [],
        public ?string $correctionReason = null,
    ) {}
}
