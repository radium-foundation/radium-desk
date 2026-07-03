<?php

namespace App\Enums;

enum RadiumBoxEnrichmentSyncStatus: string
{
    case NotSynced = 'NOT_SYNCED';
    case Pending = 'PENDING';
    case Synced = 'SYNCED';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::NotSynced => 'Not Synced',
            self::Pending => 'Pending',
            self::Synced => 'Synced',
            self::Failed => 'Failed',
        };
    }
}
