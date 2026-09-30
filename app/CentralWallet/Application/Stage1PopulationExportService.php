<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Carbon\CarbonImmutable;

final class Stage1PopulationExportService
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(string $cutoffIst = '2026-07-15 00:00:00', ?string $siteCode = 'radiumbox.com'): array
    {
        $cutoffUtc = CarbonImmutable::parse($cutoffIst, 'Asia/Kolkata')->utc();

        $wallets = CentralWallet::query()
            ->where('created_at', '>=', $cutoffUtc)
            ->orderBy('created_at')
            ->get();

        $rows = [];
        $deskCustomerIds = [];

        foreach ($wallets as $wallet) {
            $customer = CentralCustomer::query()
                ->where('central_wallet_id', $wallet->id)
                ->first();

            $cwid = (string) $wallet->id;
            $deskCustomerId = $customer !== null ? (string) $customer->id : null;

            if ($deskCustomerId !== null) {
                $deskCustomerIds[] = $deskCustomerId;
            }

            $credentials = $deskCustomerId === null
                ? []
                : $this->credentialsForCustomer($deskCustomerId);

            $accountLinks = $this->accountLinksForWallet($cwid, $siteCode);

            $ledgerBalance = $this->ledger->ledgerBalance($cwid);
            $spendableBalance = $this->ledger->spendableBalance($cwid);
            $reservedBalance = $this->ledger->reservedBalance($cwid);

            $ledgerStats = $this->ledgerStatsForWallet($cwid);

            $rows[] = [
                'central_wallet_id' => $cwid,
                'desk_customer_id' => $deskCustomerId,
                'wallet_created_at' => $wallet->created_at?->toIso8601String(),
                'wallet_status' => (string) $wallet->status,
                'ledger_balance' => $ledgerBalance,
                'spendable_balance' => $spendableBalance,
                'reserved_balance' => $reservedBalance,
                'ledger_entry_count' => $ledgerStats['entry_count'],
                'has_pending_ledger_entries' => $ledgerStats['has_pending'],
                'has_non_posted_ledger_entries' => $ledgerStats['has_non_posted'],
                'credentials' => $credentials,
                'account_links' => $accountLinks,
            ];
        }

        $uniqueCustomers = array_values(array_unique($deskCustomerIds));

        return [
            'export_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'cutoff_ist' => $cutoffIst,
            'cutoff_utc' => $cutoffUtc->toIso8601String(),
            'site_code' => $siteCode,
            'summary' => [
                'wallet_rows' => count($rows),
                'customers' => count($uniqueCustomers),
                'customers_with_credentials' => $this->countCustomersWithCredentials($rows),
                'aggregate_spendable_balance' => $this->sumSpendablePositive($rows),
            ],
            'wallets' => $rows,
        ];
    }

    /**
     * @return list<array{credential_type: string, provider: string, subject_hash: string}>
     */
    private function credentialsForCustomer(string $deskCustomerId): array
    {
        return CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $deskCustomerId)
            ->orderBy('id')
            ->get()
            ->map(static fn (CentralCustomerIdentityCredential $credential): array => [
                'credential_type' => $credential->credential_type->value,
                'provider' => (string) $credential->provider,
                'subject_hash' => (string) $credential->subject_hash,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountLinksForWallet(string $centralWalletId, ?string $siteCode): array
    {
        $query = CentralWalletAccountLink::query()
            ->where('central_wallet_id', $centralWalletId)
            ->orderBy('id');

        if ($siteCode !== null && $siteCode !== '') {
            $query->where('site_code', $siteCode);
        }

        return $query->get()
            ->map(static fn (CentralWalletAccountLink $link): array => [
                'link_id' => $link->id,
                'site_code' => (string) $link->site_code,
                'local_user_id' => (string) $link->local_user_id,
                'status' => $link->status->value,
                'verification_method' => (string) ($link->verification_method ?? ''),
                'desk_customer_id' => $link->desk_customer_id !== null ? (string) $link->desk_customer_id : null,
                'linked_at' => $link->linked_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{entry_count: int, has_pending: bool, has_non_posted: bool}
     */
    private function ledgerStatsForWallet(string $centralWalletId): array
    {
        $entries = CentralWalletLedgerEntry::query()
            ->where('central_wallet_id', $centralWalletId)
            ->get(['status']);

        $hasPending = false;
        $hasNonPosted = false;

        foreach ($entries as $entry) {
            if ($entry->status === LedgerEntryStatus::Pending) {
                $hasPending = true;
            }
            if ($entry->status !== LedgerEntryStatus::Posted) {
                $hasNonPosted = true;
            }
        }

        return [
            'entry_count' => $entries->count(),
            'has_pending' => $hasPending,
            'has_non_posted' => $hasNonPosted,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countCustomersWithCredentials(array $rows): int
    {
        return (int) collect($rows)
            ->filter(static fn (array $row): bool => $row['credentials'] !== [])
            ->pluck('desk_customer_id')
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sumSpendablePositive(array $rows): string
    {
        $total = '0.00';

        foreach ($rows as $row) {
            $spendable = (string) ($row['spendable_balance'] ?? '0.00');
            if (bccomp($spendable, '0', 2) === 1) {
                $total = bcadd($total, $spendable, 2);
            }
        }

        return $total;
    }
}
