<?php

namespace App\Enums;

enum PreShipmentCancellationStatus: string
{
    case Submitted = 'submitted';
    case HeldShipmentUnknown = 'held_shipment_unknown';
    case HeldExistingInvoice = 'held_existing_invoice';
    case HeldExecution = 'held_execution';
    case HeldServiceCase = 'held_service_case';
    case FinanceReview = 'finance_review';
    case FinanceApproved = 'finance_approved';
    case RefundRequestLinked = 'refund_request_linked';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /**
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [
            self::Completed,
            self::Rejected,
            self::Superseded,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }
}
