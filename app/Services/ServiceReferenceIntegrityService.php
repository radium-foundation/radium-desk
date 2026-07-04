<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

class ServiceReferenceIntegrityService
{
    public function assertNotAlreadyAssigned(string $transactionId, ?Order $targetOrder = null): void
    {
        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            return;
        }

        $conflictingOrder = $this->findConflictingOrder($transactionId, $targetOrder?->id);

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

    public function findConflictingOrder(string $transactionId, ?int $excludeOrderId = null): ?Order
    {
        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            return null;
        }

        $query = Order::query()
            ->where('transaction_id', $transactionId)
            ->whereNotNull('transaction_id');

        if ($excludeOrderId !== null) {
            $query->whereKeyNot($excludeOrderId);
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
