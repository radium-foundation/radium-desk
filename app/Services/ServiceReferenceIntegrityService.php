<?php

namespace App\Services;

use App\Models\Order;

/**
 * Reporting helpers for shared service references.
 *
 * Service references (orders.transaction_id) are intentionally reusable across
 * multiple orders. Cashfree payment integrity is enforced separately via the
 * unique cashfree_payment_id column and webhook ingestion guards.
 */
class ServiceReferenceIntegrityService
{
    /**
     * @return list<array{transaction_id: string, order_ids: list<int>}>
     */
    public function sharedReferenceGroups(): array
    {
        return Order::query()
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '!=', '')
            ->get(['id', 'transaction_id'])
            ->groupBy('transaction_id')
            ->filter(fn ($orders): bool => $orders->count() > 1)
            ->map(fn ($orders, string $transactionId): array => [
                'transaction_id' => $transactionId,
                'order_ids' => $orders->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ])
            ->values()
            ->all();
    }
}
