<?php

namespace App\Enums;

enum OutboundClickToCallLifecycleStatus: string
{
    case Calling = 'calling';
    case Ringing = 'ringing';
    case Answered = 'answered';
    case Busy = 'busy';
    case NoAnswer = 'no_answer';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Calling, self::Ringing, self::Answered => false,
            self::Busy, self::NoAnswer, self::Failed, self::Cancelled, self::Completed => true,
        };
    }
}
