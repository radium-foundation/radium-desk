<?php

namespace App\Support\Timeline;

use App\Data\TimelineActor;
use App\Enums\TimelineActorKind;

final class TimelineActorPresenter
{
    public const IRA_DISPLAY_NAME = 'IRA';

    public function __construct(
        private readonly TimelineActor $actor,
    ) {}

    public static function for(TimelineActor $actor): self
    {
        return new self($actor);
    }

    public function isAutomationIdentity(): bool
    {
        if ($this->actor->isAutomation || $this->actor->kind === TimelineActorKind::Automation) {
            return true;
        }

        $normalized = strtolower(trim($this->actor->displayName));

        return in_array($normalized, ['ira', 'automation', 'scheduler'], true);
    }

    public function displayName(): string
    {
        if ($this->isAutomationIdentity()) {
            return self::IRA_DISPLAY_NAME;
        }

        return $this->actor->displayName;
    }

    public function iconClass(): string
    {
        if ($this->isAutomationIdentity()) {
            return 'bi-robot';
        }

        return 'bi-person';
    }

    public function compactLabel(): string
    {
        if ($this->isAutomationIdentity()) {
            return self::IRA_DISPLAY_NAME;
        }

        if ($this->actor->kind === TimelineActorKind::Customer
            || strcasecmp($this->actor->displayName, 'Customer') === 0) {
            return 'Customer';
        }

        $name = trim($this->displayName());

        return $name;
    }

    public function subtitle(): ?string
    {
        return null;
    }

    public function showRoleBadge(): bool
    {
        return false;
    }

    public function normalizedActor(): TimelineActor
    {
        if (! $this->isAutomationIdentity()) {
            return $this->actor;
        }

        return new TimelineActor(
            displayName: self::IRA_DISPLAY_NAME,
            subtitle: null,
            isAutomation: true,
            kind: TimelineActorKind::Automation,
        );
    }
}
