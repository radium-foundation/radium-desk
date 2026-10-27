<?php

namespace App\CentralWallet\Domain\Enums;

/**
 * Desk-side classification for closed wallet refunds vs Central Wallet ledger authority.
 * Does not inspect spoke databases; "spoke execution present" is inferred from Desk execution fields only.
 */
enum WalletRefundAccountingClass: string
{
    case AuthoritativeDeskLedger = 'authoritative_desk_ledger';
    case MigrationReconciled = 'migration_reconciled';
    case SpokeExecutionWithoutDeskLedger = 'spoke_execution_without_desk_ledger';
    case DeskLedgerWithoutSpokeExecution = 'desk_ledger_without_spoke_execution';
    case NoExecutionEvidence = 'no_execution_evidence';
    case NotTerminalWalletRefund = 'not_terminal_wallet_refund';
    case Revoked = 'revoked';
}
