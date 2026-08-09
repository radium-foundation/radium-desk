<?php

namespace App\Enums;

enum CashfreeMissedBatchHealDisposition: string
{
    case WouldHeal = 'would_heal';

    case Healed = 'healed';

    case Resumed = 'resumed';

    case Skipped = 'skipped';

    case Blocked = 'blocked';

    case Failed = 'failed';
}
