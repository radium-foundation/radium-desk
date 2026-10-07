<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Http\Resources\LedgerEntryResource;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class LedgerEntryReadService
{
    public function findForCaller(
        string $callerId,
        int $ledgerEntryId,
        ?string $centralWalletId = null,
    ): ?CentralWalletLedgerEntry {
        $entry = CentralWalletLedgerEntry::query()->find($ledgerEntryId);

        if ($entry === null || $entry->source_system !== $callerId) {
            return null;
        }

        if ($centralWalletId !== null && $entry->central_wallet_id !== $centralWalletId) {
            return null;
        }

        return $entry;
    }

    public function callerCanAccessWallet(string $callerId, string $centralWalletId): bool
    {
        Cwid::fromString($centralWalletId);

        $hasActiveLink = CentralWalletAccountLink::query()
            ->where('site_code', $callerId)
            ->where('central_wallet_id', $centralWalletId)
            ->where('status', AccountLinkStatus::Active)
            ->exists();

        if ($hasActiveLink) {
            return true;
        }

        return CentralWalletLedgerEntry::query()
            ->where('central_wallet_id', $centralWalletId)
            ->where('source_system', $callerId)
            ->exists();
    }

    /**
     * @param  array{
     *     limit: int,
     *     cursor: ?LedgerEntryCursor,
     *     source_reference: ?string,
     *     business_reference: ?string,
     *     correlation_id: ?string,
     *     entry_type: ?LedgerEntryType,
     *     status: ?LedgerEntryStatus,
     *     status_explicit: bool,
     *     posted_from: ?CarbonImmutable,
     *     posted_to: ?CarbonImmutable,
     *     central_wallet_id: ?string,
     *     ledger_entry_id: ?int,
     * }  $parameters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     pagination: array{limit: int, next_cursor: ?string, has_more: bool},
     * }
     */
    public function listForWallet(string $callerId, string $centralWalletId, array $parameters): array
    {
        if (! $this->callerCanAccessWallet($callerId, $centralWalletId)) {
            throw new InvalidArgumentException('wallet_not_found');
        }

        $query = $this->baseCallerQuery($callerId)
            ->where('central_wallet_id', $centralWalletId);

        return $this->paginate($query, $parameters);
    }

    /**
     * @param  array{
     *     limit: int,
     *     cursor: ?LedgerEntryCursor,
     *     source_reference: ?string,
     *     business_reference: ?string,
     *     correlation_id: ?string,
     *     entry_type: ?LedgerEntryType,
     *     status: ?LedgerEntryStatus,
     *     status_explicit: bool,
     *     posted_from: ?CarbonImmutable,
     *     posted_to: ?CarbonImmutable,
     *     central_wallet_id: ?string,
     *     ledger_entry_id: ?int,
     * }  $parameters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     pagination: array{limit: int, next_cursor: ?string, has_more: bool},
     * }
     */
    public function listForCaller(string $callerId, array $parameters): array
    {
        $query = $this->baseCallerQuery($callerId);

        if ($parameters['central_wallet_id'] !== null) {
            $query->where('central_wallet_id', $parameters['central_wallet_id']);
        }

        return $this->paginate($query, $parameters);
    }

    /**
     * Cross-spoke Central Wallet customer history for an explicitly authorized caller.
     *
     * Does not restrict results to the caller's own source_system. Site-scoped
     * ledger-entries reads keep that restriction.
     *
     * @param  array{
     *     limit: int,
     *     cursor: ?LedgerEntryCursor,
     *     source_reference: ?string,
     *     business_reference: ?string,
     *     correlation_id: ?string,
     *     entry_type: ?LedgerEntryType,
     *     status: ?LedgerEntryStatus,
     *     status_explicit: bool,
     *     posted_from: ?CarbonImmutable,
     *     posted_to: ?CarbonImmutable,
     *     central_wallet_id: ?string,
     *     ledger_entry_id: ?int,
     * }  $parameters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     pagination: array{limit: int, next_cursor: ?string, has_more: bool},
     * }
     */
    public function listCustomerHistoryForWallet(string $centralWalletId, array $parameters): array
    {
        Cwid::fromString($centralWalletId);

        /** @var list<string> $authorizedSourceSystems */
        $authorizedSourceSystems = config('central_wallet.ledger_read.customer_history.authorized_source_systems', []);
        if ($authorizedSourceSystems === []) {
            throw new InvalidArgumentException('customer_history_not_configured');
        }

        $query = CentralWalletLedgerEntry::query()
            ->where('central_wallet_id', $centralWalletId)
            ->whereIn('source_system', $authorizedSourceSystems);

        return $this->paginateCustomerHistory($query, $parameters);
    }

    /**
     * @return Builder<CentralWalletLedgerEntry>
     */
    private function baseCallerQuery(string $callerId): Builder
    {
        return CentralWalletLedgerEntry::query()
            ->where('source_system', $callerId);
    }

    /**
     * @param  Builder<CentralWalletLedgerEntry>  $query
     * @param  array{
     *     limit: int,
     *     cursor: ?LedgerEntryCursor,
     *     source_reference: ?string,
     *     business_reference: ?string,
     *     correlation_id: ?string,
     *     entry_type: ?LedgerEntryType,
     *     status: ?LedgerEntryStatus,
     *     status_explicit: bool,
     *     posted_from: ?CarbonImmutable,
     *     posted_to: ?CarbonImmutable,
     *     central_wallet_id: ?string,
     *     ledger_entry_id: ?int,
     * }  $parameters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     pagination: array{limit: int, next_cursor: ?string, has_more: bool},
     * }
     */
    private function paginate(Builder $query, array $parameters): array
    {
        $this->applyFilters($query, $parameters);

        if ($parameters['cursor'] !== null) {
            $this->applyKeysetCursor($query, $parameters['cursor']);
        }

        $limit = $parameters['limit'];

        $entries = $query
            ->orderBy('posted_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $entries->count() > $limit;
        if ($hasMore) {
            $entries = $entries->slice(0, $limit)->values();
        }

        $data = $entries
            ->map(static fn (CentralWalletLedgerEntry $entry): array => LedgerEntryResource::toArray($entry))
            ->all();

        $nextCursor = null;
        if ($hasMore && $entries->isNotEmpty()) {
            $nextCursor = LedgerEntryCursor::fromEntry($entries->last())->encode();
        }

        return [
            'data' => $data,
            'pagination' => [
                'limit' => $limit,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * @param  Builder<CentralWalletLedgerEntry>  $query
     * @param  array{
     *     limit: int,
     *     cursor: ?LedgerEntryCursor,
     *     source_reference: ?string,
     *     business_reference: ?string,
     *     correlation_id: ?string,
     *     entry_type: ?LedgerEntryType,
     *     status: ?LedgerEntryStatus,
     *     status_explicit: bool,
     *     posted_from: ?CarbonImmutable,
     *     posted_to: ?CarbonImmutable,
     *     central_wallet_id: ?string,
     *     ledger_entry_id: ?int,
     * }  $parameters
     */
    private function applyFilters(Builder $query, array $parameters): void
    {
        if ($parameters['ledger_entry_id'] !== null) {
            $query->where('id', $parameters['ledger_entry_id']);
        }

        if ($parameters['source_reference'] !== null) {
            $query->where('source_reference', $parameters['source_reference']);
        }

        if ($parameters['business_reference'] !== null) {
            $query->where('business_reference', $parameters['business_reference']);
        }

        if ($parameters['correlation_id'] !== null) {
            $query->where('correlation_id', $parameters['correlation_id']);
        }

        if ($parameters['entry_type'] !== null) {
            $query->where('entry_type', $parameters['entry_type']);
        }

        if ($parameters['status_explicit']) {
            if ($parameters['status'] !== null) {
                $query->where('status', $parameters['status']);
            }
        } else {
            $query->where('status', LedgerEntryStatus::Posted);
        }

        if ($parameters['posted_from'] !== null) {
            $query->where('posted_at', '>=', $parameters['posted_from']);
        }

        if ($parameters['posted_to'] !== null) {
            $query->where('posted_at', '<', $parameters['posted_to']);
        }
    }

    /**
     * @param  Builder<CentralWalletLedgerEntry>  $query
     */
    private function applyKeysetCursor(Builder $query, LedgerEntryCursor $cursor): void
    {
        $postedAt = $cursor->postedAt;

        $query->where(function (Builder $builder) use ($postedAt, $cursor): void {
            $builder->where('posted_at', '>', $postedAt)
                ->orWhere(function (Builder $inner) use ($postedAt, $cursor): void {
                    $inner->where('posted_at', $postedAt)
                        ->where('id', '>', $cursor->id);
                });
        });
    }

    /**
     * @param  Builder<CentralWalletLedgerEntry>  $query
     * @param  array{
     *     limit: int,
     *     cursor: ?LedgerEntryCursor,
     *     source_reference: ?string,
     *     business_reference: ?string,
     *     correlation_id: ?string,
     *     entry_type: ?LedgerEntryType,
     *     status: ?LedgerEntryStatus,
     *     status_explicit: bool,
     *     posted_from: ?CarbonImmutable,
     *     posted_to: ?CarbonImmutable,
     *     central_wallet_id: ?string,
     *     ledger_entry_id: ?int,
     * }  $parameters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     pagination: array{limit: int, next_cursor: ?string, has_more: bool},
     * }
     */
    private function paginateCustomerHistory(Builder $query, array $parameters): array
    {
        $this->applyFilters($query, $parameters);

        if ($parameters['cursor'] !== null) {
            $this->applyKeysetCursorDescending($query, $parameters['cursor']);
        }

        $limit = $parameters['limit'];

        $entries = $query
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $entries->count() > $limit;
        if ($hasMore) {
            $entries = $entries->slice(0, $limit)->values();
        }

        $data = $entries
            ->map(static fn (CentralWalletLedgerEntry $entry): array => LedgerEntryResource::toArray($entry))
            ->all();

        $nextCursor = null;
        if ($hasMore && $entries->isNotEmpty()) {
            $nextCursor = LedgerEntryCursor::fromEntry($entries->last())->encode();
        }

        return [
            'data' => $data,
            'pagination' => [
                'limit' => $limit,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * @param  Builder<CentralWalletLedgerEntry>  $query
     */
    private function applyKeysetCursorDescending(Builder $query, LedgerEntryCursor $cursor): void
    {
        $postedAt = $cursor->postedAt;

        $query->where(function (Builder $builder) use ($postedAt, $cursor): void {
            $builder->where('posted_at', '<', $postedAt)
                ->orWhere(function (Builder $inner) use ($postedAt, $cursor): void {
                    $inner->where('posted_at', $postedAt)
                        ->where('id', '<', $cursor->id);
                });
        });
    }
}
