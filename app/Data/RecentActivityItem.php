<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class RecentActivityItem
{
    public function __construct(
        public string $stream,
        public string $title,
        public ?string $typePill,
        public string $indicatorVariant,
        public ?string $incidentReference,
        public ?int $entityIncidentId,
        public ?string $entityReference,
        public Carbon $occurredAt,
        public string $compactTime,
        public string $exactTime,
        public string $actorName,
        public bool $isAutomation,
    ) {}
}
