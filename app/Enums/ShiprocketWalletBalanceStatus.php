<?php

namespace App\Enums;

enum ShiprocketWalletBalanceStatus: string
{
    case Available = 'available';
    case Zero = 'zero';
    case Low = 'low';
    case Unknown = 'unknown';
    case AuthError = 'auth_error';
    case ProviderError = 'provider_error';

    public function showsBalanceAmount(): bool
    {
        return in_array($this, [self::Available, self::Zero, self::Low], true);
    }

    public function uiTone(): string
    {
        return match ($this) {
            self::Zero => 'critical',
            self::Low => 'warning',
            self::Available => 'neutral',
            default => 'muted',
        };
    }
}
