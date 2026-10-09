<?php

namespace App\CentralWallet\Application;

enum CashfreeHistoricalIdentityRepairIdentityClass: string
{
    case AlreadyBound = 'ALREADY_BOUND';
    case ExactExistingCustomer = 'EXACT_EXISTING_CUSTOMER';
    case NewCustomerRequired = 'NEW_CUSTOMER_REQUIRED';
    case Ambiguous = 'AMBIGUOUS';
    case InvalidOrMissingEmail = 'INVALID_OR_MISSING_EMAIL';
}
