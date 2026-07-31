<?php

namespace App\Data\Workforce;

readonly class WorkforceMember360LeaveItem
{
    public function __construct(
        public int $id,
        public string $startDate,
        public string $endDate,
        public string $dateRangeLabel,
        public string $status,
        public string $statusLabel,
        public string $duration,
        public string $durationLabel,
        public string $reason,
        public ?string $url,
    ) {}
}
