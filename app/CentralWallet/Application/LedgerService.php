<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LedgerService
{
    public function __construct(
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function appendEntry(
        string $centralWalletId,
        LedgerEntryType $entryType,
        string $amount,
        string $sourceSystem,
        string $correlationId,
        ?string $sourceReference = null,
        ?string $businessReference = null,
        array $metadata = [],
    ): CentralWalletLedgerEntry {
        Cwid::fromString($centralWalletId);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Ledger amount must be positive.');
        }

        $currency = (string) config('central_wallet.currency', 'INR');

        return DB::transaction(function () use (
            $centralWalletId,
            $entryType,
            $amount,
            $currency,
            $sourceSystem,
            $correlationId,
            $sourceReference,
            $businessReference,
            $metadata,
        ): CentralWalletLedgerEntry {
            $wallet = CentralWallet::query()->lockForUpdate()->find($centralWalletId);
            if ($wallet === null) {
                throw new InvalidArgumentException('Central wallet not found.');
            }

            if ($entryType === LedgerEntryType::Debit) {
                $available = $this->availableBalance($centralWalletId);
                if (bccomp($available, $amount, 2) < 0) {
                    throw new InvalidArgumentException('Insufficient available balance.');
                }
            }

            $entry = CentralWalletLedgerEntry::query()->create([
                'central_wallet_id' => $centralWalletId,
                'entry_type' => $entryType,
                'amount' => $amount,
                'currency' => $currency,
                'status' => LedgerEntryStatus::Posted,
                'source_system' => $sourceSystem,
                'source_reference' => $sourceReference,
                'correlation_id' => $correlationId,
                'business_reference' => $businessReference,
                'metadata' => $metadata,
                'posted_at' => now(),
            ]);

            $this->auditEvents->record(
                eventType: 'ledger.entry_appended',
                centralWalletId: $centralWalletId,
                actorType: AuditActorType::Service,
                actorId: $sourceSystem,
                correlationId: $correlationId,
                payload: [
                    'ledger_entry_id' => $entry->id,
                    'entry_type' => $entryType->value,
                    'amount' => $amount,
                    'currency' => $currency,
                    'source_reference' => $sourceReference,
                    'business_reference' => $businessReference,
                ],
            );

            return $entry;
        });
    }

    public function availableBalance(string $centralWalletId): string
    {
        $credits = (string) CentralWalletLedgerEntry::query()
            ->where('central_wallet_id', $centralWalletId)
            ->where('status', LedgerEntryStatus::Posted)
            ->whereIn('entry_type', [LedgerEntryType::Credit, LedgerEntryType::Reversal, LedgerEntryType::Adjustment])
            ->sum('amount');

        $debits = (string) CentralWalletLedgerEntry::query()
            ->where('central_wallet_id', $centralWalletId)
            ->where('status', LedgerEntryStatus::Posted)
            ->where('entry_type', LedgerEntryType::Debit)
            ->sum('amount');

        return bcsub($credits, $debits, 2);
    }
}
