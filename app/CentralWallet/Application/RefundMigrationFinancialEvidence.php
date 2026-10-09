<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;

final class RefundMigrationFinancialEvidence
{
    public static function isReconciledWithEvidence(CentralWalletRefundMigration $migration): bool
    {
        return filled($migration->destination_ledger_entry_id)
            && filled($migration->source_debit_reference);
    }
}
