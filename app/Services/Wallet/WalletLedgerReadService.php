<?php

namespace App\Services\Wallet;

use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxWalletLedgerClient;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WalletLedgerReadService
{
    public function __construct(
        private readonly RadiumBoxWalletLedgerClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forIncident(Incident $incident, User $viewer, array $filters = []): array
    {
        $incident->loadMissing('order');

        $email = $this->resolveCustomerEmail($incident);

        try {
            $payload = $this->client->fetchLedger($email, $this->normalizeFilters($filters));
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 404) {
                return $this->emptyLedgerView($email, 'No RadiumBox wallet account was found for this customer.');
            }

            throw $exception;
        }

        return $this->presentLedger($payload, $viewer, $incident, $email);
    }

    private function resolveCustomerEmail(Incident $incident): string
    {
        $order = $incident->order;
        $email = strtolower(trim((string) ($order?->customer_email ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'wallet' => 'Customer email is required before wallet ledger can be loaded.',
            ]);
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $type = strtolower(trim((string) ($filters['type'] ?? 'all')));
        if (! in_array($type, ['all', 'credit', 'debit'], true)) {
            $type = 'all';
        }

        return [
            'limit' => isset($filters['limit']) ? (int) $filters['limit'] : 25,
            'before_id' => isset($filters['before_id']) ? (int) $filters['before_id'] : null,
            'type' => $type === 'all' ? null : $type,
            'status' => $this->nullableString($filters['status'] ?? null),
            'order_code' => $this->nullableString($filters['order_code'] ?? null),
            'desk_refund_reference' => $this->nullableString($filters['desk_refund_reference'] ?? null),
            'reference' => $this->nullableString($filters['reference'] ?? null),
            'date_from' => $this->nullableString($filters['date_from'] ?? null),
            'date_to' => $this->nullableString($filters['date_to'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function presentLedger(array $payload, User $viewer, Incident $incident, string $email): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $transactions = is_array($data['transactions'] ?? null) ? $data['transactions'] : [];
        $balance = is_array($data['balance'] ?? null) ? $data['balance'] : [];

        $deskRefundReferences = collect($transactions)
            ->pluck('desk_refund_reference')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();

        $orderCodes = collect($transactions)
            ->pluck('order_code')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();

        $refundMap = $deskRefundReferences === []
            ? collect()
            : RefundRequest::query()
                ->whereIn('reference_no', $deskRefundReferences)
                ->get(['id', 'reference_no'])
                ->keyBy('reference_no');

        $deskOrderMap = $orderCodes === []
            ? collect()
            : Order::query()
                ->whereIn('order_id', $orderCodes)
                ->get(['id', 'order_id'])
                ->keyBy('order_id');

        $rows = collect($transactions)->map(function (array $transaction) use ($refundMap, $deskOrderMap, $viewer): array {
            $deskRefundReference = is_string($transaction['desk_refund_reference'] ?? null)
                ? trim($transaction['desk_refund_reference'])
                : '';

            $refund = $deskRefundReference !== '' ? $refundMap->get($deskRefundReference) : null;

            $orderCode = is_string($transaction['order_code'] ?? null)
                ? trim($transaction['order_code'])
                : '';

            $deskOrder = $orderCode !== '' ? $deskOrderMap->get($orderCode) : null;

            return [
                'id' => (int) ($transaction['id'] ?? 0),
                'created_at' => $this->formatIst($transaction['created_at'] ?? null),
                'type' => strtoupper((string) ($transaction['type'] ?? 'neutral')),
                'credit' => $transaction['credit'] ?? null,
                'debit' => $transaction['debit'] ?? null,
                'status' => (string) ($transaction['status'] ?? ''),
                'message' => (string) ($transaction['message'] ?? ''),
                'order_code' => $orderCode !== '' ? $orderCode : null,
                'desk_order_id' => $deskOrder?->id,
                'desk_refund_reference' => $deskRefundReference !== '' ? $deskRefundReference : null,
                'desk_refund_id' => $refund?->id,
                'can_view_refund' => $refund !== null && $viewer->can('refunds.view'),
                'reference' => $transaction['txnid'] ?? (string) ($transaction['id'] ?? ''),
                'badges' => $this->badgesFor($transaction, $refund, $deskOrder),
            ];
        })->values()->all();

        return [
            'customer_email' => $email,
            'incident_id' => $incident->id,
            'balance' => [
                'available' => round((float) ($balance['available'] ?? 0), 2),
                'pending_credits' => round((float) ($balance['pending_credits'] ?? 0), 2),
                'pending_debits' => round((float) ($balance['pending_debits'] ?? 0), 2),
                'cached_wallet_amount' => isset($balance['cached_wallet_amount'])
                    ? round((float) $balance['cached_wallet_amount'], 2)
                    : null,
            ],
            'last_activity_at' => $this->formatIst($data['last_activity_at'] ?? null),
            'transactions' => $rows,
            'pagination' => is_array($data['pagination'] ?? null) ? $data['pagination'] : [
                'has_more' => false,
                'next_before_id' => null,
            ],
            'show_running_balance' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return list<string>
     */
    private function badgesFor(array $transaction, ?RefundRequest $refund, ?Order $deskOrder): array
    {
        $badges = [];
        $status = strtolower((string) ($transaction['status'] ?? ''));
        $deskRefundReference = is_string($transaction['desk_refund_reference'] ?? null)
            ? trim($transaction['desk_refund_reference'])
            : '';

        if ($status === 'pending') {
            $badges[] = 'Pending';
        }

        if ($deskRefundReference !== '') {
            $badges[] = 'Desk Auto Credit';
            $badges[] = $refund !== null ? 'Refund Linked' : 'Unmatched Refund';
        } elseif (! empty($transaction['admin_id'])) {
            $badges[] = 'Manual/Admin';
        }

        if (($transaction['missing_order_link'] ?? false) === true) {
            $badges[] = 'Missing Order Link';
        } elseif ($deskOrder === null && is_string($transaction['order_code'] ?? null) && trim($transaction['order_code']) !== '') {
            $badges[] = 'Missing Order Link';
        }

        return array_values(array_unique($badges));
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
