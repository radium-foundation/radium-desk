<?php

namespace App\Enums;

enum TimelineActorKind: string
{
    case System = 'system';
    case Customer = 'customer';
    case Agent = 'agent';
    case Automation = 'automation';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Customer => 'Customer',
            self::Agent => 'Team Member',
            self::Automation => 'Automation',
        };
    }
}
