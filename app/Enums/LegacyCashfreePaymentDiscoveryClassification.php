<?php

namespace App\Enums;

enum LegacyCashfreePaymentDiscoveryClassification: string
{
    case SafeToLink = 'SAFE_TO_LINK';
    case AlreadyLinked = 'ALREADY_LINKED';
    case NoCashfreePayment = 'NO_CASHFREE_PAYMENT';
    case MultiplePayments = 'MULTIPLE_PAYMENTS';
    case AmountMismatch = 'AMOUNT_MISMATCH';
    case CustomerMismatch = 'CUSTOMER_MISMATCH';
    case ConflictingDeskPayment = 'CONFLICTING_DESK_PAYMENT';
    case RefundConflict = 'REFUND_CONFLICT';
    case Ambiguous = 'AMBIGUOUS';
}
