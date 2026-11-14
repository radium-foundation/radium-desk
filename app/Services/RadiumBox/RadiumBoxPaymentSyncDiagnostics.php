<?php

namespace App\Services\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Paid RadiumBox orders at Desk missing commerce ingest or stuck in retriable sync states.
 */
final class RadiumBoxPaymentSyncDiagnostics
{
    public static function incompleteHandoffCount(): int
    {
        return self::baseQuery()->count();
    }

    /**
     * @return list<string>
     */
    public static function sampleBusinessOrderIds(int $limit = 25): array
    {
        return self::baseQuery()
            ->orderByDesc('orders.id')
            ->limit($limit)
            ->pluck('orders.order_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    private static function baseQuery(): Builder
    {
        return Order::query()
            ->cashfreeVerified()
            ->where(function (Builder $prefix): void {
                $prefix->where('order_id', 'like', 'RDE%')
                    ->orWhere('order_id', 'like', 'RBP%')
                    ->orWhere(function (Builder $inner): void {
                        $inner->where('order_id', 'like', 'RB%')
                            ->where('order_id', 'not like', 'RBP%')
                            ->where('order_id', 'not like', 'RBX%');
                    });
            })
            ->whereNotExists(function ($commerce): void {
                $commerce->selectRaw('1')
                    ->from('commerce_orders')
                    ->whereColumn('commerce_orders.source_id', 'orders.order_id');
            })
            ->whereIn('radiumbox_sync_status', [
                RadiumBoxEnrichmentSyncStatus::ReconciliationRequired->value,
                RadiumBoxEnrichmentSyncStatus::HandoffPending->value,
                RadiumBoxEnrichmentSyncStatus::HandoffFailed->value,
                RadiumBoxEnrichmentSyncStatus::Pending->value,
                RadiumBoxEnrichmentSyncStatus::Failed->value,
                RadiumBoxEnrichmentSyncStatus::NotSynced->value,
            ]);
    }
}