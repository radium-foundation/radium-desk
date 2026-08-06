<?php

namespace App\Enums;

enum IraMemoryType: string
{
    case Classification = 'classification';
    case Owner = 'owner';
    case Ignore = 'ignore';
    case Disposition = 'disposition';
    case CustomerPattern = 'customer_pattern';
    case VendorPattern = 'vendor_pattern';
    case RefundPattern = 'refund_pattern';
    case RoutingPattern = 'routing_pattern';
    case AppointmentPattern = 'appointment_pattern';

    public function label(): string
    {
        return match ($this) {
            self::Classification => 'Classification',
            self::Owner => 'Owner',
            self::Ignore => 'Ignore',
            self::Disposition => 'Disposition',
            self::CustomerPattern => 'Customer Pattern',
            self::VendorPattern => 'Vendor Pattern',
            self::RefundPattern => 'Refund Pattern',
            self::RoutingPattern => 'Routing Pattern',
            self::AppointmentPattern => 'Appointment Pattern',
        };
    }

    public static function fromDecisionKind(IraMemoryDecisionKind|IncomingEmailLearningDecisionType|string $decisionKind): self
    {
        $value = $decisionKind instanceof \BackedEnum ? $decisionKind->value : $decisionKind;

        return match ($value) {
            IraMemoryDecisionKind::Assign->value,
            IncomingEmailLearningDecisionType::Assign->value => self::Owner,
            IraMemoryDecisionKind::Classification->value,
            IncomingEmailLearningDecisionType::Classification->value => self::Classification,
            IraMemoryDecisionKind::Ignore->value,
            IncomingEmailLearningDecisionType::Ignore->value => self::Ignore,
            IraMemoryDecisionKind::Importance->value,
            IncomingEmailLearningDecisionType::Importance->value => self::RoutingPattern,
            IraMemoryDecisionKind::Disposition->value => self::Disposition,
            default => self::Classification,
        };
    }
}
