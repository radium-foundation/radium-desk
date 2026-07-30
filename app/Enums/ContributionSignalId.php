<?php

namespace App\Enums;

/**
 * Contribution signal catalog (Rule Book §5 + Work Contribution Policy).
 */
enum ContributionSignalId: string
{
    case ActiveDuration = 'active_duration';
    case CasesHandled = 'cases_handled';
    case CasesResolved = 'cases_resolved';
    case CasesClosed = 'cases_closed';
    case Communications = 'communications';
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case Calls = 'calls';
    case StatusUpdates = 'status_updates';
    case Remarks = 'remarks';
    case OrdersActivated = 'orders_activated';
    case Sales = 'sales';
    case ManualAdjustment = 'manual_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::ActiveDuration => 'Active duration',
            self::CasesHandled => 'Cases handled',
            self::CasesResolved => 'Cases resolved (session events)',
            self::CasesClosed => 'Cases closed',
            self::Communications => 'Communications (combined session)',
            self::Email => 'Email',
            self::Whatsapp => 'WhatsApp',
            self::Calls => 'Calls',
            self::StatusUpdates => 'Status updates',
            self::Remarks => 'Remarks',
            self::OrdersActivated => 'Orders activated',
            self::Sales => 'Sales',
            self::ManualAdjustment => 'Manual adjustment',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::ActiveDuration => 'seconds',
            default => 'count',
        };
    }
}
