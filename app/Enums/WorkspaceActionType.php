<?php

namespace App\Enums;

enum WorkspaceActionType: string
{
    case Assign = 'assign';
    case Close = 'close';
    case Reopen = 'reopen';
    case Escalate = 'escalate';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
