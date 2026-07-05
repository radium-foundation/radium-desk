<?php

namespace App\Enums;

enum WorkSessionEndReason: string
{
    case ManualLogout = 'manual_logout';
    case AwayTimeout = 'away_timeout';
    case SessionReplaced = 'session_replaced';

    public function label(): string
    {
        return match ($this) {
            self::ManualLogout => 'Manual logout',
            self::AwayTimeout => 'Away timeout',
            self::SessionReplaced => 'Session replaced',
        };
    }
}
