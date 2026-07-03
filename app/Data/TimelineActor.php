<?php

namespace App\Data;

use App\Enums\TimelineActorKind;

readonly class TimelineActor
{
    public function __construct(
        public string $displayName,
        public ?string $subtitle = null,
        public bool $isAutomation = false,
        public ?TimelineActorKind $kind = null,
    ) {}

    public function isVisible(): bool
    {
        return $this->displayName !== '';
    }

    public function roleLabel(): string
    {
        if ($this->kind !== null) {
            return $this->kind->label();
        }

        if ($this->isAutomation) {
            return TimelineActorKind::Automation->label();
        }

        if (strcasecmp($this->displayName, 'Customer') === 0) {
            return TimelineActorKind::Customer->label();
        }

        if (strcasecmp($this->displayName, 'System') === 0) {
            return TimelineActorKind::System->label();
        }

        return TimelineActorKind::Agent->label();
    }

    public function roleVariant(): string
    {
        return match ($this->roleLabel()) {
            TimelineActorKind::Customer->label() => TimelineActorKind::Customer->value,
            TimelineActorKind::System->label() => TimelineActorKind::System->value,
            TimelineActorKind::Automation->label() => TimelineActorKind::Automation->value,
            default => TimelineActorKind::Agent->value,
        };
    }

    public function actorFilterTag(): string
    {
        return match ($this->roleLabel()) {
            TimelineActorKind::Customer->label() => 'customer',
            TimelineActorKind::System->label(),
            TimelineActorKind::Automation->label() => 'system',
            default => 'support',
        };
    }
}
