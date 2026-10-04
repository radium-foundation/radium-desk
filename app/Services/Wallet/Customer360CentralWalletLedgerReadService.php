<?php

namespace App\Services\Wallet;

use App\CentralWallet\Application\LedgerEntryCursor;
use App\CentralWallet\Application\LedgerEntryReadService;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Models\Incident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class Customer360CentralWalletLedgerReadService
{
    public function __construct(
        private readonly Customer360CentralWalletIdentityLookup $identityLookup,
        private readonly LedgerEntryReadService $ledgerReads,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forIncident(Incident $incident, User $viewer, array $filters = []): array
    {
        $incident->loadMissing('order');
        $email = strtolower(trim((string) ($incident->order?->customer_email ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'wallet' => 'Customer email is required before wallet ledger can be loaded.',
            ]);
        }

        $identity = $this->identityLookup->resolveForIncident($incident);
        if ($identity === null) {
            return $this->emptyLedgerView($email, 'No Central Wallet account was found for this customer.');
        }

        $cwid = $identity['central_wallet_id'];

        try {
            $parameters = $this->buildListParameters($filters);
            $result = $this->ledgerReads->listCustomerHistoryForWallet($cwid, $parameters);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'customer_history_not_configured') {
                throw new RuntimeException('Central Wallet customer history read is not configured.');
            }

            throw $exception;
        }

        $transactions = array_map(
            fn (array $entry): array => $this->mapLedgerEntryToTransaction($entry),
            $result['data'] ?? [],
        );

        $filteredTransactions = $this->applyLegacyFilters($transactions, $filters);

        $pagination = $result['pagination'] ?? [];
        $nextBeforeId = null;
        if (($pagination['has_more'] ?? false) && $filteredTransactions !== []) {
            $last = end($filteredTransactions);
            $nextBeforeId = (int) ($last['id'] ?? 0) ?: null;
        }

        $availableBalance = $this->ledger->availableBalance($cwid);
        $lastActivityAt = $filteredTransactions[0]['created_at'] ?? null;

        return [
            'customer_email' => $email,
            'incident_id' => $incident->id,
            'balance' => [
                'available' => round((float) $availableBalance, 2),
                'pending_credits' => 0.0,
                'pending_debits' => 0.0,
                'cached_wallet_amount' => null,
            ],
            'last_activity_at' => $lastActivityAt !== null
                ? $this->formatIst($lastActivityAt)
                : null,
            'transactions' => $filteredTransactions,
            'pagination' => [
                'has_more' => (bool) ($pagination['has_more'] ?? false),
                'next_before_id' => $nextBeforeId,
            ],
            'show_running_balance' => false,
            'wallet_source' => WalletLedgerSource::CentralWallet->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function buildListParameters(array $filters): array
    {
        $defaultLimit = max(1, (int) config('central_wallet.ledger_read.default_page_size', 25));
        $maxLimit = max(1, (int) config('central_wallet.ledger_read.max_page_size', 100));
        $limit = isset($filters['limit'])
            ? max(1, min($maxLimit, (int) $filters['limit']))
            : $defaultLimit;

        $entryType = null;
        $type = strtolower(trim((string) ($filters['type'] ?? 'all')));
        if ($type === 'credit') {
            $entryType = LedgerEntryType::Credit;
        } elseif ($type === 'debit') {
            $entryType = LedgerEntryType::Debit;
        }

        $cursor = null;
        if (isset($filters['before_id']) && is_numeric($filters['before_id'])) {
            $beforeEntry = CentralWalletLedgerEntry::query()->find((int) $filters['before_id']);
            if ($beforeEntry !== null) {
                $cursor = LedgerEntryCursor::fromEntry($beforeEntry);
            }
        }

        $postedFrom = null;
        $postedTo = null;
        if (is_string($filters['date_from'] ?? null) && trim($filters['date_from']) !== '') {
            $postedFrom = CarbonImmutable::parse($filters['date_from'], 'Asia/Kolkata')->startOfDay();
        }
        if (is_string($filters['date_to'] ?? null) && trim($filters['date_to']) !== '') {
            $postedTo = CarbonImmutable::parse($filters['date_to'], 'Asia/Kolkata')->addDay()->startOfDay();
        }

        $statusExplicit = isset($filters['status']) && trim((string) $filters['status']) !== '';
        $status = null;
        if ($statusExplicit) {
            $rawStatus = strtolower(trim((string) $filters['status']));
            $status = match ($rawStatus) {
                'success', 'posted' => LedgerEntryStatus::Posted,
                default => null,
            };
        }

        return [
            'limit' => $limit,
            'cursor' => $cursor,
            'source_reference' => $this->orderSourceReferenceFilter($filters['order_code'] ?? null),
            'business_reference' => $this->nullableString($filters['desk_refund_reference'] ?? null),
            'correlation_id' => null,
            'entry_type' => $entryType,
            'status' => $status,
            'status_explicit' => $statusExplicit && $status !== null,
            'posted_from' => $postedFrom,
            'posted_to' => $postedTo,
            'central_wallet_id' => null,
            'ledger_entry_id' => $this->walletReferenceLedgerId($filters['reference'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function mapLedgerEntryToTransaction(array $entry): array
    {
        $entryType = strtolower((string) ($entry['entry_type'] ?? ''));
        $amount = (string) ($entry['amount'] ?? '0.00');
        $businessReference = is_string($entry['business_reference'] ?? null)
            ? trim($entry['business_reference'])
            : '';
        $sourceReference = is_string($entry['source_reference'] ?? null)
            ? trim($entry['source_reference'])
            : '';
        $ledgerEntryId = (int) ($entry['ledger_entry_id'] ?? 0);
        $postedAt = (string) ($entry['posted_at'] ?? $entry['created_at'] ?? '');

        return [
            'id' => $ledgerEntryId,
            'created_at' => $postedAt,
            'type' => $entryType === 'debit' ? 'debit' : 'credit',
            'credit' => $entryType === 'credit' ? $amount : null,
            'debit' => $entryType === 'debit' ? $amount : null,
            'status' => strtolower((string) ($entry['status'] ?? 'posted')) === 'posted' ? 'success' : (string) ($entry['status'] ?? ''),
            'message' => $this->transactionMessage($entryType, $amount, $businessReference, $sourceReference),
            'orderid' => null,
            'order_code' => $this->orderCodeFromSourceReference($sourceReference),
            'txnid' => $ledgerEntryId > 0 ? 'CW:'.$ledgerEntryId : null,
            'desk_refund_reference' => $businessReference !== '' && str_starts_with($businessReference, 'REF-')
                ? $businessReference
                : null,
            'admin_id' => null,
            'missing_order_link' => false,
            'wallet_source' => WalletLedgerSource::CentralWallet->value,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function applyLegacyFilters(array $transactions, array $filters): array
    {
        $reference = $this->nullableString($filters['reference'] ?? null);
        if ($reference === null) {
            return $transactions;
        }

        return array_values(array_filter(
            $transactions,
            function (array $transaction) use ($reference): bool {
                $txReference = (string) ($transaction['txnid'] ?? '');

                return $txReference === $reference
                    || $txReference === 'CW:'.$reference
                    || (string) ($transaction['id'] ?? '') === $reference;
            },
        ));
    }

    private function transactionMessage(
        string $entryType,
        string $amount,
        string $businessReference,
        string $sourceReference,
    ): string {
        $orderCode = $this->orderCodeFromSourceReference($sourceReference);
        $orderSuffix = $orderCode !== null ? " for order {$orderCode}" : '';
        $refSuffix = $businessReference !== '' ? " ({$businessReference})" : '';

        return match ($entryType) {
            'debit' => "Central Wallet debit of INR {$amount}{$orderSuffix}{$refSuffix}",
            default => "Central Wallet credit of INR {$amount}{$orderSuffix}{$refSuffix}",
        };
    }

    private function orderCodeFromSourceReference(string $sourceReference): ?string
    {
        if (preg_match('/^desk_refund:([A-Za-z0-9]+)$/', $sourceReference, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^desk_refund_reversal:([A-Za-z0-9]+)$/', $sourceReference, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function orderSourceReferenceFilter(mixed $orderCode): ?string
    {
        $orderCode = $this->nullableString($orderCode);
        if ($orderCode === null) {
            return null;
        }

        return 'desk_refund:'.$orderCode;
    }

    private function walletReferenceLedgerId(mixed $reference): ?int
    {
        $reference = $this->nullableString($reference);
        if ($reference === null) {
            return null;
        }

        if (preg_match('/^CW:(\d+)$/', $reference, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^\d+$/', $reference) === 1) {
            return (int) $reference;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLedgerView(string $email, string $message): array
    {
        return [
            'customer_email' => $email,
            'empty_message' => $message,
            'balance' => [
                'available' => 0.0,
                'pending_credits' => 0.0,
                'pending_debits' => 0.0,
                'cached_wallet_amount' => null,
            ],
            'last_activity_at' => null,
            'transactions' => [],
            'pagination' => [
                'has_more' => false,
                'next_before_id' => null,
            ],
            'show_running_balance' => false,
            'wallet_source' => WalletLedgerSource::CentralWallet->value,
        ];
    }

    private function formatIst(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->timezone('Asia/Kolkata')->format('d M Y h:i A');
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
