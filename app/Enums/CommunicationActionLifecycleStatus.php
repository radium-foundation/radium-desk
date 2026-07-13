<?php

namespace App\Enums;

enum CommunicationActionLifecycleStatus: string
{
    case Available = 'available';
    case Opened = 'opened';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Opened => 'Opened',
            self::Sent => 'Sent',
            self::Skipped => 'Skipped',
            self::Completed => 'Completed',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Available => [self::Opened],
            self::Opened => [self::Sent, self::Skipped],
            self::Sent, self::Skipped => [self::Completed],
            self::Completed => [self::Available],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
