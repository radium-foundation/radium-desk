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

    public function roleLabel(): string
    {
        if ($this->isAutomation) {
            return 'System';
        }

        if (strcasecmp($this->displayName, 'Customer') === 0) {
            return 'Customer';
        }

        return 'Agent';
    }

    public function roleVariant(): string
    {
        return match ($this->roleLabel()) {
            'Customer' => 'customer',
            'System' => 'system',
            default => 'agent',
        };
    }
}
