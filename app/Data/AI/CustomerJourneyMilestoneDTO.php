<?php

namespace App\Data\AI;

use App\Enums\AI\CustomerJourneyMilestoneType;
use Illuminate\Support\Carbon;

readonly class CustomerJourneyMilestoneDTO
{
    public function __construct(
        public CustomerJourneyMilestoneType $type,
        public string $title,
        public Carbon $timestamp,
        public string $status,
        public ?string $actor,
        public string $source,
        public int $confidence,
    ) {}
}
