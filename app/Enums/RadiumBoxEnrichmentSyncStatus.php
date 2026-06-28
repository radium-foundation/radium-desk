<?php

namespace App\Enums;

enum RadiumBoxEnrichmentSyncStatus: string
{
    case Pending = 'PENDING';
    case Synced = 'SYNCED';
    case Failed = 'FAILED';
}
