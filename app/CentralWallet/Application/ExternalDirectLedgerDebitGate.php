<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\LedgerEntryType;

/**
 * Controls whether external site integrations may append direct ledger debits
 * via POST /wallets/{cwid}/ledger-entries. Reservation commit and internal
 * service callers are not subject to this gate.
 */
final class ExternalDirectLedgerDebitGate
{
    public const INTERNAL_CALLER = 'central_wallet_service';

    public function rejectsExternalDirectDebit(string $callerId, LedgerEntryType $entryType): bool
    {
        if ($entryType !== LedgerEntryType::Debit) {
            return false;
        }

        if ($callerId === self::INTERNAL_CALLER) {
            return false;
        }

        return ! (bool) config('central_wallet.direct_ledger_debit.enabled', true);
    }
}
