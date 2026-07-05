<?php

namespace App\Enums;

enum CashfreeHistoricalRecoveryDisposition: string
{
    case Recoverable = 'recoverable';

    case AlreadyExists = 'already_exists';

    case Unsafe = 'unsafe';
}
