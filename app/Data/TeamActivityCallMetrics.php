<?php

namespace App\Data;

readonly class TeamActivityCallMetrics
{
    public function __construct(
        public int $answeredCount,
        public int $totalCount,
        public int $talkDurationSeconds,
        public string $talkDurationLabel,
    ) {}

    public function hasActivity(): bool
    {
        return $this->totalCount > 0
            || $this->answeredCount > 0
            || $this->talkDurationSeconds > 0;
    }
}
