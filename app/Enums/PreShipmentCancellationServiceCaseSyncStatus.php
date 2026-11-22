<?php

namespace App\Enums;

enum PreShipmentCancellationServiceCaseSyncStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';
}
