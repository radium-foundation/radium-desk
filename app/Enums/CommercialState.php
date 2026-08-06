<?php

namespace App\Enums;

/**
 * First-class commercial posture for a service case (BR-04).
 *
 * Priority (highest first): RefundCompleted → RefundInitiated → CaseClosed → ServiceRestored → Open.
 * ServiceRestored is returned instead of RefundCompleted when an active
 * commercial_service_restorations row exists for that order/refund pair.
 */
enum CommercialState: string
{
    case Open = 'open';
    case CaseClosed = 'case_closed';
    case RefundInitiated = 'refund_initiated';
    case RefundCompleted = 'refund_completed';
    case ServiceRestored = 'service_restored';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::CaseClosed => 'Case Closed',
            self::RefundInitiated => 'Refund Initiated',
            self::RefundCompleted => 'Refund Completed',
            self::ServiceRestored => 'Service Restored',
        };
    }

    public function bannerVariant(): string
    {
        return match ($this) {
            self::Open => 'muted',
            self::CaseClosed => 'success',
            self::RefundInitiated => 'warning',
            self::RefundCompleted => 'danger',
            self::ServiceRestored => 'info',
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::RefundCompleted => 400,
            self::RefundInitiated => 300,
            self::CaseClosed => 200,
            self::ServiceRestored => 150,
            self::Open => 100,
        };
    }

    public function allowsCommercialWork(): bool
    {
        return in_array($this, [self::Open, self::ServiceRestored], true);
    }

    public function isRefundBlocking(): bool
    {
        return in_array($this, [self::RefundInitiated, self::RefundCompleted], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
