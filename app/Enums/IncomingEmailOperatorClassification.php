<?php

namespace App\Enums;

/**
 * Operator-facing classification choices for the IRA Learning Center.
 * Maps to stored IncomingEmailClassification values — never expose internals in UI.
 */
enum IncomingEmailOperatorClassification: string
{
    case Support = 'support';
    case Sales = 'sales';
    case Refund = 'refund';
    case Vendor = 'vendor';
    case Docs = 'docs';
    case Promotion = 'promotion';
    case Spam = 'spam';
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Support => 'Support',
            self::Sales => 'Sales',
            self::Refund => 'Refund',
            self::Vendor => 'Vendor',
            self::Docs => 'Docs',
            self::Promotion => 'Promotion',
            self::Spam => 'Spam',
            self::Automatic => 'Auto Processed',
        };
    }

    public function toStoredClassification(): IncomingEmailClassification
    {
        return match ($this) {
            self::Support => IncomingEmailClassification::Support,
            self::Sales => IncomingEmailClassification::PossibleSalesLead,
            self::Refund => IncomingEmailClassification::Refund,
            self::Vendor => IncomingEmailClassification::VendorAction,
            self::Docs => IncomingEmailClassification::Docs,
            self::Promotion => IncomingEmailClassification::Promotional,
            self::Spam => IncomingEmailClassification::Spam,
            self::Automatic => IncomingEmailClassification::OtherIgnored,
        };
    }

    public static function fromStored(?IncomingEmailClassification $classification): ?self
    {
        if ($classification === null) {
            return null;
        }

        return match ($classification) {
            IncomingEmailClassification::Support,
            IncomingEmailClassification::Appointment,
            IncomingEmailClassification::ExistingCustomer => self::Support,
            IncomingEmailClassification::PossibleSalesLead,
            IncomingEmailClassification::UnknownCustomer => self::Sales,
            IncomingEmailClassification::Refund => self::Refund,
            IncomingEmailClassification::VendorAction,
            IncomingEmailClassification::FinanceAction,
            IncomingEmailClassification::HrAction => self::Vendor,
            IncomingEmailClassification::Docs => self::Docs,
            IncomingEmailClassification::Promotional,
            IncomingEmailClassification::Marketing,
            IncomingEmailClassification::Newsletter => self::Promotion,
            IncomingEmailClassification::Spam,
            IncomingEmailClassification::Social,
            IncomingEmailClassification::Forum => self::Spam,
            IncomingEmailClassification::OwnOutbound,
            IncomingEmailClassification::OtherIgnored => self::Automatic,
        };
    }
}
