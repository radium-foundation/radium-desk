<?php

namespace App\Enums;

enum RadiumBoxEnrichmentSyncStatus: string
{
    case NotSynced = 'NOT_SYNCED';
    case Pending = 'PENDING';
    case Synced = 'SYNCED';
    case Failed = 'FAILED';
    case HandoffPending = 'HANDOFF_PENDING';
    case HandoffFailed = 'HANDOFF_FAILED';
    case ReconciliationRequired = 'RECONCILIATION_REQUIRED';

    public function label(): string
    {
        return match ($this) {
            self::NotSynced => 'Not Synced',
            self::Pending => 'Pending',
            self::Synced => 'Synced',
            self::Failed => 'Failed',
            self::HandoffPending => 'Handoff Pending',
            self::HandoffFailed => 'Handoff Failed',
            self::ReconciliationRequired => 'Reconciliation Required',
        };
    }
}
