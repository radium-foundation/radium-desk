<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case PendingCreate = 'pending_create';
    case Created = 'created';
    case AwbAssigned = 'awb_assigned';
    case Failed = 'failed';
    case Ambiguous = 'ambiguous';
}
