<?php

namespace App\Enums;

enum ServiceCaseCloseExceptionReason: string
{
    case CustomerCancelledBeforePayment = 'customer_cancelled_before_payment';
    case DuplicateServiceCase = 'duplicate_service_case';
    case WarrantyRejected = 'warranty_rejected';
    case ProductNeverReachedServiceCentre = 'product_never_reached_service_centre';
    case ReplacementIssued = 'replacement_issued';
    case CashCollectedOffline = 'cash_collected_offline';
    case ApprovedByAdmin = 'approved_by_admin';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerCancelledBeforePayment => 'Customer cancelled before payment',
            self::DuplicateServiceCase => 'Duplicate service case',
            self::WarrantyRejected => 'Warranty rejected',
            self::ProductNeverReachedServiceCentre => 'Product never reached service centre',
            self::ReplacementIssued => 'Replacement issued',
            self::CashCollectedOffline => 'Cash collected offline',
            self::ApprovedByAdmin => 'Approved by Admin',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
