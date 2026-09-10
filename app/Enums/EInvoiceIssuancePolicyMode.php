<?php

namespace App\Enums;

enum EInvoiceIssuancePolicyMode: string
{
    case HardwareOnly = 'hardware_only';
    case AllEligibleB2b = 'all_eligible_b2b';
}
