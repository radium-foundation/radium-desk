<?php

namespace App\Enums;

enum InventoryAvailabilitySyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
}
