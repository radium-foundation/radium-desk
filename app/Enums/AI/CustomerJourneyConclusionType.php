<?php

namespace App\Enums\AI;

enum CustomerJourneyConclusionType: string
{
    case Complete = 'complete';
    case Interrupted = 'interrupted';
    case Blocked = 'blocked';
    case Reopened = 'reopened';
    case InProgress = 'in_progress';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Journey complete',
            self::Interrupted => 'Journey interrupted',
            self::Blocked => 'Journey blocked',
            self::Reopened => 'Journey reopened',
            self::InProgress => 'Journey in progress',
        };
    }
}
