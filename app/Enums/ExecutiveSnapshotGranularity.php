<?php

namespace App\Enums;

enum ExecutiveSnapshotGranularity: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Monthly = 'monthly';
}
