<?php

namespace App\Data\Bonvoice;

use Illuminate\Support\Carbon;

readonly class CustomerContactIntelligence
{
    public function __construct(
        public int $totalToday,
        public int $missedToday,
        public int $answeredToday,
        public ?Carbon $lastContactAt,
        public ?string $summaryLine,
        public int $contactsLast24Hours,
        public bool $highUrgency,
    ) {}
}
