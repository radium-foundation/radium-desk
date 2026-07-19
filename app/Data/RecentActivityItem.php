<?php

namespace App\Data;

use App\Models\User;
use Illuminate\Support\Carbon;

readonly class RecentActivityItem
{
    /**
     * @param  list<string>  $includes
     */
    public function __construct(
        public string $title,
        public string $icon,
        public ?string $sourceBadge,
        public string $indicatorVariant,
        public ?string $entityLabel,
        public ?string $entityUrl,
        public Carbon $occurredAt,
        public string $relativeTime,
        public string $exactTime,
        public string $actorName,
        public string $actorIconClass,
        public bool $isAutomation,
        public ?User $actorUser = null,
        public array $includes = [],
    ) {}
}
