<?php

namespace App\Enums;

enum RefundRevocationAttemptStatus: string
{
    case Pending = 'pending';
    case WalletReversed = 'wallet_reversed';
    case Completed = 'completed';
    case Failed = 'failed';
}
