<?php

namespace App\Enums\StatutoryCoverage;

enum StatutoryCoverageClassification: string
{
    case IssuedSynchronized = 'issued_synchronized';
    case IssuedSyncPending = 'issued_sync_pending';
    case IssuedSyncUnknown = 'issued_sync_unknown';
    case MissingCommerce = 'missing_commerce';
    case IneligibleCommerce = 'ineligible_commerce';
    case EligibleMissingInvoice = 'eligible_missing_invoice';
    case UnmatchedConflict = 'unmatched_conflict';
    case SourceNonObligating = 'source_non_obligating';
    case SourcePopulationUnavailable = 'source_population_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::IssuedSynchronized => 'Invoice issued and synchronized',
            self::IssuedSyncPending => 'Invoice issued; spoke sync pending',
            self::IssuedSyncUnknown => 'Invoice issued; spoke sync unknown',
            self::MissingCommerce => 'Obligation missing Desk commerce',
            self::IneligibleCommerce => 'Commerce present; invoice ineligible',
            self::EligibleMissingInvoice => 'Eligible commerce; invoice missing',
            self::UnmatchedConflict => 'Unmatched or conflicting records',
            self::SourceNonObligating => 'Source order non-obligating or not yet eligible',
            self::SourcePopulationUnavailable => 'Source population unavailable',
        };
    }
}
