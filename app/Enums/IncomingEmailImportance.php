<?php

namespace App\Enums;

enum IncomingEmailImportance: string
{
    case Normal = 'normal';
    case High = 'high';
    case Escalation = 'escalation';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::High => 'High',
            self::Escalation => 'Escalation',
        };
    }
}
