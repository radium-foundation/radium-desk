<?php

namespace App\Enums\Assignment;

enum AssignmentQueue: string
{
    case Ready = 'ready';
    case Support = 'support';
    case WaitingCustomer = 'waiting_customer';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready Queue',
            self::Support => 'Support Queue',
            self::WaitingCustomer => 'Waiting Customer',
            self::Completed => 'Completed',
        };
    }
}
