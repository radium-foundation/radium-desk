<?php

namespace App\Enums;

enum MissingSerialAutomationStatus: string
{
    case Requested = 'requested';
    case Reminded = 'reminded';
    case Escalated = 'escalated';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Reminded => 'Reminded',
            self::Escalated => 'Escalated',
            self::Completed => 'Completed',
        };
    }
}
