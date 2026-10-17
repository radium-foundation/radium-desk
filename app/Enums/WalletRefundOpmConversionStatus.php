<?php

namespace App\Enums;

enum WalletRefundOpmConversionStatus: string
{
    case PendingApproval = 'pending_approval';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Reserving = 'reserving';
    case Reserved = 'reserved';
    case ReservationFailed = 'reservation_failed';
    case PayoutRecorded = 'payout_recorded';
    case ReconciliationHold = 'reconciliation_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::ReservationFailed,
            self::Completed,
            self::Cancelled,
        ], true);
    }

    public function countsTowardOrderRefundLimit(): bool
    {
        return ! in_array($this, [
            self::Rejected,
            self::Cancelled,
            self::ReservationFailed,
        ], true);
    }

    public function holdsSiteReservation(): bool
    {
        return in_array($this, [
            self::Reserved,
            self::PayoutRecorded,
            self::ReconciliationHold,
        ], true);
    }
}
