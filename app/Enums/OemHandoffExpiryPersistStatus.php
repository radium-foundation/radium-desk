<?php

namespace App\Enums;

enum OemHandoffExpiryPersistStatus: string
{
    case Persisted = 'persisted';
    case PersistedDuplicate = 'persisted_duplicate';
    case Updated = 'updated';
    case SkippedDisabled = 'skipped_disabled';
    case SkippedNoExpiry = 'skipped_no_expiry';
    case QuarantinedMissingServiceReference = 'quarantined_missing_service_reference';
    case QuarantinedInvalidServiceReference = 'quarantined_invalid_service_reference';

    public function isQuarantined(): bool
    {
        return in_array($this, [
            self::QuarantinedMissingServiceReference,
            self::QuarantinedInvalidServiceReference,
        ], true);
    }
}
