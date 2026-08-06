<?php

namespace App\Enums;

enum IraMemoryDecisionKind: string
{
    case Assign = 'assign';
    case Classification = 'classification';
    case Importance = 'importance';
    case Ignore = 'ignore';
    case Disposition = 'disposition';

    public function label(): string
    {
        return match ($this) {
            self::Assign => 'Assign',
            self::Classification => 'Classification',
            self::Importance => 'Importance',
            self::Ignore => 'Ignore',
            self::Disposition => 'Disposition',
        };
    }
}
