<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

class ServiceReferenceIntegrityService
{
    /**
     * @param  list<int>  $batchOrderIds  Order IDs in the current bulk selection; duplicates within this set are allowed.
     */
    public function assertNotAlreadyAssigned(
        string $transactionId,
        ?Order $targetOrder = null,
        array $batchOrderIds = [],
    ): void {
        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            return;
        }

        $conflictingOrder = $this->findConflictingOrder(
            $transactionId,
            $targetOrder?->id,
            $batchOrderIds,
        );

        if ($conflictingOrder === null) {
            return;
        }

        throw ValidationException::withMessages([
            'transaction_id' => sprintf(
                'This service reference is already linked to order %s.',
                $conflictingOrder->order_id,
            ),
        ]);
    }

    /**
     * @param  list<int>  $batchOrderIds  Order IDs in the current bulk selection; duplicates within this set are allowed.
     */
    public function findConflictingOrder(
        string $transactionId,
        ?int $excludeOrderId = null,
        array $batchOrderIds = [],
    ): ?Order {
        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            return null;
        }

        $excludedOrderIds = collect($batchOrderIds)
            ->map(fn ($id): int => (int) $id)
            ->when(
                $excludeOrderId !== null,
                fn ($ids) => $ids->push((int) $excludeOrderId),
            )
            ->unique()
            ->values()
            ->all();

        $query = Order::query()
            ->where('transaction_id', $transactionId)
            ->whereNotNull('transaction_id');

        if ($excludedOrderIds !== []) {
            $query->whereNotIn('id', $excludedOrderIds);
        }

        return $query->first();
    }

    /**
     * @return list<array{transaction_id: string, order_ids: list<int>}>
     */
    public function duplicateReferenceGroups(): array
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
