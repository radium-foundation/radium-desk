<?php

namespace App\Enums;

enum InventoryFinanceHandoffStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Posted => 'Posted',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
            self::Reversed => 'Reversed',
        };
    }
}
