<?php

namespace App\Enums;

enum OrderCompletionStatus: string
{
    case PendingAdmin = 'pending_admin';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::PendingAdmin => 'Pending Admin',
            self::Completed => 'Completed',
        };
    }
}
