<?php

namespace App\CentralWallet\Domain\Enums;

enum RefundMigrationStatus: string
{
    case Pending = 'pending';
    case Prepared = 'prepared';
    case SourceDebitPending = 'source_debit_pending';
    case SourceDebited = 'source_debited';
    case CwCreditPending = 'cw_credit_pending';
    case CwCredited = 'cw_credited';
    case Reconciled = 'reconciled';
    case Failed = 'failed';
    case Compensating = 'compensating';
    case Compensated = 'compensated';
    case ReconciliationRequired = 'reconciliation_required';
    case Reversed = 'reversed';
}
