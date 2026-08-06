<?php

namespace App\Enums;

enum IncomingEmailClassification: string
{
    case Support = 'support';
    case Refund = 'refund';
    case Appointment = 'appointment';
    case ExistingCustomer = 'existing_customer';
    case UnknownCustomer = 'unknown_customer';
    case PossibleSalesLead = 'possible_sales_lead';
    case VendorAction = 'vendor_action';
    case FinanceAction = 'finance_action';
    case HrAction = 'hr_action';
    case Docs = 'docs';
    case Spam = 'spam';
    case Promotional = 'promotional';
    case Social = 'social';
    case Newsletter = 'newsletter';
    case Marketing = 'marketing';
    case Forum = 'forum';
    case OwnOutbound = 'own_outbound';
    case OtherIgnored = 'other_ignored';

    public function isOperational(): bool
    {
        return match ($this) {
            self::Support,
            self::Refund,
            self::Appointment,
            self::ExistingCustomer,
            self::UnknownCustomer,
            self::PossibleSalesLead,
            self::VendorAction,
            self::FinanceAction,
            self::HrAction => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Support => 'Support',
            self::Refund => 'Refund',
            self::Appointment => 'Appointment',
            self::ExistingCustomer => 'Existing customer',
            self::UnknownCustomer => 'Unknown customer',
            self::PossibleSalesLead => 'Possible sales lead',
            self::VendorAction => 'Vendor action',
            self::FinanceAction => 'Finance action',
            self::HrAction => 'HR action',
            self::Docs => 'Docs',
            self::Spam => 'Spam',
            self::Promotional => 'Promotional',
            self::Social => 'Social',
            self::Newsletter => 'Newsletter',
            self::Marketing => 'Marketing',
            self::Forum => 'Forum',
            self::OwnOutbound => 'Own outbound',
            self::OtherIgnored => 'Other ignored',
        };
    }
}
