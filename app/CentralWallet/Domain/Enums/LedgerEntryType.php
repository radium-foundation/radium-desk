<?php

namespace App\CentralWallet\Domain\Enums;

enum LedgerEntryType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
}
