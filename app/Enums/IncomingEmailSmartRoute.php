<?php

namespace App\Enums;

enum IncomingEmailSmartRoute: string
{
    case ExistingCustomerNewCase = 'existing_customer_new_case';
    case RefundEnquiry = 'refund_enquiry';
    case SalesEnquiry = 'sales_enquiry';
    case SupportEnquiry = 'support_enquiry';
    case NeedsHuman = 'needs_human';

    public function label(): string
    {
        return match ($this) {
            self::ExistingCustomerNewCase => 'Existing customer — new service case',
            self::RefundEnquiry => 'Refund enquiry',
            self::SalesEnquiry => 'Sales enquiry',
            self::SupportEnquiry => 'Support enquiry',
            self::NeedsHuman => 'Needs human action',
        };
    }

    public function timelineTeamLabel(): ?string
    {
        return match ($this) {
            self::RefundEnquiry => 'Refund',
            self::SalesEnquiry => 'Sales',
            self::SupportEnquiry => 'Support',
            self::ExistingCustomerNewCase => 'Support',
            default => null,
        };
    }

    public function autoCreatesServiceCase(): bool
    {
        return match ($this) {
            self::ExistingCustomerNewCase,
            self::RefundEnquiry,
            self::SalesEnquiry,
            self::SupportEnquiry => true,
            self::NeedsHuman => false,
        };
    }
}
