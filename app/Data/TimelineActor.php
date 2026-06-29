<?php

namespace App\Data;

readonly class TimelineActor
{
    public function __construct(
        public string $displayName,
        public ?string $subtitle = null,
        public bool $isAutomation = false,
    ) {}

    public function isVisible(): bool
    {
        return $this->displayName !== '';
    }
}
