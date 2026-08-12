<?php

namespace App\Enums;

enum LeaveAmendmentSource: string
{
    case AgentRequest = 'agent_request';
    case HrDirect = 'hr_direct';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
