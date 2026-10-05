<?php

namespace Tests\Support;

use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CentralWalletLedgerTestHelper
{
    protected function seedCentralWallet(string $cwid): void
    {
        if (DB::table('central_wallets')->where('id', $cwid)->exists()) {
            return;
        }

        DB::table('central_wallets')->insert([
            'id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedPostedCreditLedgerEntry(
        string $cwid,
        string $businessReference,
        string $orderId,
        string $amount,
        string $sourceSystem = 'rdservice.in',
        ?int $ledgerEntryId = null,
    ): int {
        $this->seedCentralWallet($cwid);

        $id = $ledgerEntryId ?? (((int) DB::table('central_wallet_ledger_entries')->max('id')) + 1);

        DB::table('central_wallet_ledger_entries')->insert([
            'id' => $id,
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Credit->value,
            'amount' => $amount,
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted->value,
            'source_system' => $sourceSystem,
            'source_reference' => 'desk_refund:'.$orderId,
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => $businessReference,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    protected function seedPostedReversalForEntry(int $originalLedgerEntryId, string $cwid, string $businessReference): void
    {
        $id = ((int) DB::table('central_wallet_ledger_entries')->max('id')) + 1;

        DB::table('central_wallet_ledger_entries')->insert([
            'id' => $id,
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Reversal->value,
            'amount' => DB::table('central_wallet_ledger_entries')->where('id', $originalLedgerEntryId)->value('amount'),
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted->value,
            'source_system' => 'rdservice.in',
            'source_reference' => 'desk_refund_reversal:test',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => $businessReference,
            'original_ledger_entry_id' => $originalLedgerEntryId,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
