<?php

namespace App\Enums;

enum CatalogPriceSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
}
