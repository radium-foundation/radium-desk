<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletBalanceMigration;

final class BalanceMigrationFinancialEvidence
{
    public static function isReconciledWithEvidence(CentralWalletBalanceMigration $migration): bool
    {
        if ($migration->status !== BalanceMigrationStatus::Reconciled) {
            return false;
        }

        return self::hasDestinationLedger($migration) && self::hasSourceRetirement($migration);
    }

    public static function hasDestinationLedger(CentralWalletBalanceMigration $migration): bool
    {
        return filled($migration->destination_ledger_entry_id);
    }

    public static function hasSourceRetirement(CentralWalletBalanceMigration $migration): bool
    {
        return filled($migration->source_retirement_reference);
    }
}
