<?php

namespace App\Enums;

enum StatutoryCancellationWorkflow: string
{
    case B2bWithinWindowCancelInvoice = 'b2b_within_window_cancel_invoice';
    case B2bBeyondWindowCreditNote = 'b2b_beyond_window_credit_note';
    case B2bNoIrnCancelInvoice = 'b2b_no_irn_cancel_invoice';
    case B2cCancelInvoice = 'b2c_cancel_invoice';
    case B2cAdjustmentPending = 'b2c_adjustment_pending';
}
