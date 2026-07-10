<?php

namespace App\Enums;

enum TeamBroadcastAudience: string
{
    case AllTeam = 'all_team';
    case OperationsTeam = 'operations_team';
    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::AllTeam => 'All team',
            self::OperationsTeam => 'Operations team',
            self::Selected => 'Selected members',
        };
    }
}
