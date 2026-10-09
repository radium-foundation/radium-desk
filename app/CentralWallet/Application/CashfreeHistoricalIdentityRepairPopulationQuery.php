<?php

namespace App\CentralWallet\Application;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P-04-10-156 population semantics for pre-v4.1.15 Cashfree historical identity repair.
 */
final class CashfreeHistoricalIdentityRepairPopulationQuery
{
    public function deployCutoffUtc(): Carbon
    {
        $raw = (string) config('central_wallet.historical_identity_repair.deploy_cutoff_utc', '2026-10-09 12:51:27');

        return Carbon::parse($raw, 'UTC');
    }

    /**
     * @return Builder<Order>
     */
    public function eligibleOrdersQuery(): Builder
    {
        $cutoff = $this->deployCutoffUtc();

        return Order::query()
            ->whereNull('deleted_at')
            ->whereNotNull('cashfree_payment_id')
            ->where('cashfree_payment_id', '!=', '')
            ->where('created_at', '<', $cutoff)
            ->where('order_id', 'not like', 'INQ-%')
            ->whereExists(function (QueryBuilder $query): void {
                $query->select(DB::raw(1))
                    ->from('cashfree_webhook_logs as w')
                    ->whereColumn('w.cf_payment_id', 'orders.cashfree_payment_id')
                    ->where('w.processing_status', 'processed');
            });
    }

    /**
     * @param  list<int>|null  $orderPrimaryKeys
     * @param  list<string>|null  $businessOrderIds
     */
    public function applySelectionFilters(Builder $query, ?array $orderPrimaryKeys, ?array $businessOrderIds, ?string $normalizedEmail): Builder
    {
        if ($orderPrimaryKeys !== null && $orderPrimaryKeys !== []) {
            $query->whereIn('id', $orderPrimaryKeys);
        }

        if ($businessOrderIds !== null && $businessOrderIds !== []) {
            $query->whereIn('order_id', $businessOrderIds);
        }

        if ($normalizedEmail !== null && $normalizedEmail !== '') {
            $query->whereRaw('LOWER(TRIM(customer_email)) = ?', [strtolower(trim($normalizedEmail))]);
        }

        return $query;
    }
}
