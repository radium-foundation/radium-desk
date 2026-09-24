<?php

namespace App\Enums\StatutoryInvoice;

enum ServiceStatutoryInvoiceMintTrigger: string
{
    case ServiceReferenceCompleted = 'service_reference_completed';
    case CustomerWaitingAutoClose = 'customer_waiting_auto_close';
    case ServiceCaseClosed = 'service_case_closed';
    case InvoiceRetry = 'invoice_retry';
    case InvoiceReconciliation = 'invoice_reconciliation';
    case ManualFinance = 'manual_finance';
}
