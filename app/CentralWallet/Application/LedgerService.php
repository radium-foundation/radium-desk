<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\ReservationState;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletReservation;
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
        ?string $reservationId = null,
        ?int $originalLedgerEntryId = null,
        array $metadata = [],
    ): CentralWalletLedgerEntry {
        Cwid::fromString($centralWalletId);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Ledger amount must be positive.');
        }

        if ($entryType === LedgerEntryType::Reversal && $originalLedgerEntryId === null) {
            throw new InvalidArgumentException('original_ledger_entry_id is required for reversal entries.');
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
            $reservationId,
            $originalLedgerEntryId,
            $metadata,
        ): CentralWalletLedgerEntry {
            $wallet = CentralWallet::query()->lockForUpdate()->find($centralWalletId);
            if ($wallet === null) {
                throw new InvalidArgumentException('Central wallet not found.');
            }

            if ($entryType === LedgerEntryType::Debit) {
                $spendable = $this->spendableBalance($centralWalletId);
                if (bccomp($spendable, $amount, 2) < 0) {
                    throw new InvalidArgumentException('Insufficient available balance.');
                }
            }

            if ($entryType === LedgerEntryType::Reversal) {
                $original = CentralWalletLedgerEntry::query()->find($originalLedgerEntryId);
                if ($original === null) {
                    throw new InvalidArgumentException('Original ledger entry not found.');
                }

                if ($original->central_wallet_id !== $centralWalletId) {
                    throw new InvalidArgumentException('Original ledger entry does not belong to this wallet.');
                }

                if ($original->status !== LedgerEntryStatus::Posted) {
                    throw new InvalidArgumentException('Original ledger entry is not posted.');
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
                'reservation_id' => $reservationId,
                'original_ledger_entry_id' => $originalLedgerEntryId,
                'metadata' => $metadata === [] ? null : $metadata,
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
                    'reservation_id' => $reservationId,
                    'original_ledger_entry_id' => $originalLedgerEntryId,
                ],
            );

            if ($entryType === LedgerEntryType::Reversal) {
                $this->auditEvents->record(
                    eventType: 'ledger.reversal_linked',
                    centralWalletId: $centralWalletId,
                    actorType: AuditActorType::Service,
                    actorId: $sourceSystem,
                    correlationId: $correlationId,
                    payload: [
                        'reversal_ledger_entry_id' => $entry->id,
                        'original_ledger_entry_id' => $originalLedgerEntryId,
                        'amount' => $amount,
                        'business_reference' => $businessReference,
                    ],
                );
            }

            return $entry;
        });
    }

    public function ledgerBalance(string $centralWalletId): string
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

    public function availableBalance(string $centralWalletId): string
    {
        return $this->spendableBalance($centralWalletId);
    }

    public function spendableBalance(string $centralWalletId): string
    {
        return bcsub($this->ledgerBalance($centralWalletId), $this->reservedBalance($centralWalletId), 2);
    }

    public function reservedBalance(string $centralWalletId): string
    {
        $reserved = (string) CentralWalletReservation::query()
            ->where('central_wallet_id', $centralWalletId)
            ->where('state', ReservationState::Active)
            ->where('expires_at', '>', now())
            ->sum('amount');

        return bcadd($reserved, '0', 2);
    }
}
