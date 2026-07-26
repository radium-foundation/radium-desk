<?php

namespace App\Data;

readonly class TeamActivityPresenceMetrics
{
    public function __construct(
        public int $sessionsToday,
        public int $todayDurationSeconds,
        public ?int $currentDurationSeconds,
        public bool $hasOpenSession,
        public ?string $todayDurationLabel = null,
        public ?string $currentDurationLabel = null,
    ) {}
}
