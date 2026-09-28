<?php

namespace App\CentralWallet\Domain\Enums;

enum LedgerEntryStatus: string
{
    case Posted = 'posted';
    case Pending = 'pending';
    case Voided = 'voided';
}
