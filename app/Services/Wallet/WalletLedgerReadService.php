<?php

namespace App\Services\Wallet;

use App\CentralWallet\Application\LedgerEntryCursor;
use App\CentralWallet\Application\LedgerEntryReadService;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

class WalletLedgerReadService
{
    public const PAGE_SIZE = 25;

    /**
     * @var array<string, string>
     */
    private const SITE_LABELS = [
        'radiumbox.com' => 'RadiumBox',
        'rdservice.in' => 'rdservice.in',
        'rdservice.net' => 'rdservice.net',
    ];

    public function __construct(
        private readonly DeskCustomerCentralWalletResolver $customers,
        private readonly LedgerService $ledger,
        private readonly LedgerEntryReadService $history,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forIncident(Incident $incident, User $viewer, array $filters = []): array
    {
        unset($filters['central_wallet_id'], $filters['cwid'], $filters['customer_email'], $filters['before_id']);

        $identity = $this->customers->forIncident($incident);
        if ($identity['state'] !== 'resolved') {
            return $this->terminalView($incident, 'unresolved');
        }

        try {
            $available = $this->ledger->availableBalance($identity['central_wallet_id']);
            $reserved = $this->ledger->reservedBalance($identity['central_wallet_id']);
            $page = $this->history->listCustomerHistoryForWallet(
                $identity['central_wallet_id'],
                $this->historyParameters($filters),
            );
        } catch (Throwable) {
            return $this->terminalView($incident, 'unavailable');
        }

        return $this->presentLedger($page, $viewer, $incident, $identity, $available, $reserved);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     limit: int,
     *     cursor: ?LedgerEntryCursor,
     *     source_reference: ?string,
     *     business_reference: ?string,
     *     correlation_id: ?string,
     *     entry_type: ?LedgerEntryType,
     *     status: null,
     *     status_explicit: false,
     *     posted_from: ?CarbonImmutable,
     *     posted_to: ?CarbonImmutable,
     *     central_wallet_id: null,
     *     ledger_entry_id: null,
     * }
     */
    private function historyParameters(array $filters): array
    {
        $cursor = $this->nullableString($filters['cursor'] ?? null);
        if ($cursor !== null) {
            $cursor = LedgerEntryCursor::decode($cursor);
        }

        $type = strtolower(trim((string) ($filters['type'] ?? 'all')));
        $entryType = LedgerEntryType::tryFrom($type);

        return [
            'limit' => self::PAGE_SIZE,
            'cursor' => $cursor,
            'source_reference' => null,
            'business_reference' => $this->nullableString($filters['business_reference'] ?? null),
            'correlation_id' => null,
            'entry_type' => $entryType,
            'status' => null,
            'status_explicit' => false,
            'posted_from' => null,
            'posted_to' => null,
            'central_wallet_id' => null,
            'ledger_entry_id' => null,
        ];
    }

    /**
     * @param  array{data: list<array<string, mixed>>, pagination: array{limit: int, next_cursor: ?string, has_more: bool}}  $page
     * @param  array{state: 'resolved', customer_id: string, central_wallet_id: string, wallet_status: string}  $identity
     * @return array<string, mixed>
     */
    private function presentLedger(
        array $page,
        User $viewer,
        Incident $incident,
        array $identity,
        string $available,
        string $reserved,
    ): array {
        $entries = $page['data'];
        $ids = array_values(array_filter(array_map(
            static fn (array $entry): int => (int) ($entry['ledger_entry_id'] ?? 0),
            $entries,
        )));

        $parents = $ids === []
            ? collect()
            : CentralWalletLedgerEntry::query()
                ->where('central_wallet_id', $identity['central_wallet_id'])
                ->whereIn('id', $ids)
                ->pluck('original_ledger_entry_id', 'id');

        $businessReferences = collect($entries)
            ->pluck('business_reference')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();

        $refundMap = $businessReferences === []
            ? collect()
            : RefundRequest::query()
                ->whereIn('reference_no', $businessReferences)
                ->get(['id', 'reference_no'])
                ->keyBy('reference_no');

        $orderMap = $businessReferences === []
            ? collect()
            : Order::query()
                ->whereIn('order_id', $businessReferences)
                ->get(['id', 'order_id'])
                ->keyBy('order_id');

        $rows = array_map(function (array $entry) use ($parents, $refundMap, $orderMap, $viewer): array {
            $businessReference = is_string($entry['business_reference'] ?? null)
                ? trim($entry['business_reference'])
                : '';
            $sourceSystem = (string) ($entry['source_system'] ?? '');
            $ledgerEntryId = (int) ($entry['ledger_entry_id'] ?? 0);
            $parentId = $parents->get($ledgerEntryId);
            $refund = $businessReference !== '' ? $refundMap->get($businessReference) : null;
            $order = $businessReference !== '' ? $orderMap->get($businessReference) : null;

            return [
                'ledger_entry_id' => $ledgerEntryId,
                'entry_type' => (string) ($entry['entry_type'] ?? ''),
                'amount' => (string) ($entry['amount'] ?? ''),
                'currency' => (string) ($entry['currency'] ?? ''),
                'status' => (string) ($entry['status'] ?? ''),
                'source_system' => $sourceSystem,
                'source_label' => self::SITE_LABELS[$sourceSystem] ?? $sourceSystem,
                'source_reference' => is_string($entry['source_reference'] ?? null) ? $entry['source_reference'] : null,
                'business_reference' => $businessReference !== '' ? $businessReference : null,
                'correlation_id' => (string) ($entry['correlation_id'] ?? ''),
                'posted_at' => $this->formatIst($entry['posted_at'] ?? null),
                'created_at' => $this->formatIst($entry['created_at'] ?? null),
                'reservation_id' => is_string($entry['reservation_id'] ?? null) ? $entry['reservation_id'] : null,
                'original_ledger_entry_id' => is_numeric($parentId) ? (int) $parentId : null,
                'desk_order_id' => $order?->id,
                'desk_refund_id' => $refund?->id,
                'can_view_refund' => $refund !== null && $viewer->can('refunds.view'),
            ];
        }, $entries);

        return [
            'state' => 'ready',
            'incident_id' => $incident->id,
            'customer_id' => $identity['customer_id'],
            'wallet' => [
                'available' => $available,
                'reserved' => $reserved,
                'masked_id' => $this->maskCwid($identity['central_wallet_id']),
                'status' => $identity['wallet_status'],
                'source' => 'Central Wallet',
            ],
            'transactions' => $rows,
            'pagination' => $page['pagination'],
            'empty_message' => $rows === []
                ? 'No posted Central Wallet transactions for this customer.'
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalView(Incident $incident, string $state): array
    {
        return [
            'state' => $state,
            'incident_id' => $incident->id,
            'customer_id' => null,
            'wallet' => null,
            'transactions' => [],
            'pagination' => [
                'limit' => self::PAGE_SIZE,
                'next_cursor' => null,
                'has_more' => false,
            ],
            'empty_message' => null,
        ];
    }

    private function maskCwid(string $centralWalletId): string
    {
        $compact = str_replace('-', '', $centralWalletId);

        return '····'.substr($compact, -4);
    }

    private function formatIst(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->timezone('Asia/Kolkata')->format('d M Y, H:i');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
