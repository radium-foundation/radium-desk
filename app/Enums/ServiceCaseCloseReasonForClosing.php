<?php

namespace App\Enums;

enum ServiceCaseCloseReasonForClosing: string
{
    case IssueResolved = 'issue_resolved';
    case CustomerNotResponding = 'customer_not_responding';
    case CustomerCancelled = 'customer_cancelled';
    case ReferenceNumberPending = 'reference_number_pending';
    case SerialNumberPending = 'serial_number_pending';
    case WarrantyRejected = 'warranty_rejected';
    case ReplacementIssued = 'replacement_issued';
    case PaymentCollectedOffline = 'payment_collected_offline';
    case DuplicateCase = 'duplicate_case';
    case ApprovedByAdmin = 'approved_by_admin';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::IssueResolved => 'Issue Resolved',
            self::CustomerNotResponding => 'Customer Not Responding',
            self::CustomerCancelled => 'Customer Cancelled',
            self::ReferenceNumberPending => 'Reference Number Pending',
            self::SerialNumberPending => 'Serial Number Pending',
            self::WarrantyRejected => 'Warranty Rejected',
            self::ReplacementIssued => 'Replacement Issued',
            self::PaymentCollectedOffline => 'Payment Collected Offline',
            self::DuplicateCase => 'Duplicate Case',
            self::ApprovedByAdmin => 'Approved by Admin',
            self::Other => 'Other',
        };
    }

    public function showsCustomerNotification(): bool
    {
        return match ($this) {
            self::IssueResolved,
            self::CustomerCancelled,
            self::ReplacementIssued,
            self::Other => true,
            default => false,
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
