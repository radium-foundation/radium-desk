<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only diagnostics for paid hardware support orders missing a fulfilment row.
 */
final class HardwareFulfilmentGapDiagnostics
{
    /**
     * Paid RDE/RBP orders in window with no hardware_fulfilment and a support serial
     * on ingested hardware commerce (fulfilment-not-opened cohort).
     */
    public static function count(Carbon $fromIst, Carbon $toIst): int
    {
        return self::baseQuery($fromIst, $toIst)->count();
    }

    /**
     * @return list<string>
     */
    public static function sampleSourceIds(Carbon $fromIst, Carbon $toIst, int $limit = 25): array
    {
        return self::baseQuery($fromIst, $toIst)
            ->orderByDesc('orders.id')
            ->limit($limit)
            ->pluck('orders.order_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    public static function constrainUnfulfilledHardwareWorkCandidates(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->where(function (Builder $awaitingSerial): void {
                $awaitingSerial->whereNull('orders.serial_number')
                    ->orWhere('orders.serial_number', '');
            })->orWhere(function (Builder $supportSerialGap): void {
                $supportSerialGap->whereNotNull('orders.serial_number')
                    ->where('orders.serial_number', '!=', '');
                self::constrainHasIngestedHardwareCommerce($supportSerialGap);
            });
        })->where(function (Builder $tx): void {
            $tx->whereNull('orders.transaction_id')
                ->orWhere('orders.transaction_id', '');
        });
    }

    public static function constrainHasIngestedHardwareCommerce(Builder $query): void
    {
        $query->whereExists(function ($commerce): void {
            $commerce->selectRaw('1')
                ->from('commerce_orders')
                ->where(function ($join): void {
                    $join->whereColumn('commerce_orders.support_order_id', 'orders.id')
                        ->orWhereColumn('commerce_orders.source_id', 'orders.order_id');
                })
                ->whereIn('commerce_orders.status', [
                    CommerceOrderStatus::Validated->value,
                    CommerceOrderStatus::Eligible->value,
                    CommerceOrderStatus::InvoicePending->value,
                    CommerceOrderStatus::Invoiced->value,
                ])
                ->where('commerce_orders.payment_status', 'paid')
                ->where(function ($tender): void {
                    $tender->whereNull('commerce_orders.wallet_tender_amount')
                        ->orWhere('commerce_orders.wallet_tender_amount', '<', 0.01);
                })
                ->whereExists(function ($item): void {
                    $item->selectRaw('1')
                        ->from('commerce_order_items as coi')
                        ->whereColumn('coi.commerce_order_id', 'commerce_orders.id')
                        ->where(function ($physical): void {
                            $physical->where('coi.shipping_line_kind', HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND)
                                ->orWhereNotNull('coi.model_id');
                        });
                });
        });
    }

    private static function baseQuery(Carbon $fromIst, Carbon $toIst): Builder
    {
        $fromBound = HardwareFulfilmentEligibility::createdAtSqlBound($fromIst);
        $toBound = HardwareFulfilmentEligibility::createdAtSqlBound($toIst);
        $blocked = array_values(array_unique(array_merge(
            HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS,
            HardwareFulfilmentEligibility::HOLD_SOURCE_IDS,
            HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS,
        )));

        $query = Order::query()
            ->where(function (Builder $prefix): void {
                foreach (HardwareFulfilmentEligibility::HARDWARE_SOURCE_PREFIXES as $hardwarePrefix) {
                    $prefix->orWhere('orders.order_id', 'like', $hardwarePrefix.'%');
                }
            })
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('hardware_fulfilments')
                    ->whereColumn('hardware_fulfilments.source_id', 'orders.order_id');
            })
            ->whereNotNull('orders.cashfree_payment_id')
            ->where('orders.cashfree_payment_id', '!=', '')
            ->whereNotIn('orders.order_id', $blocked)
            ->where('orders.created_at', '>=', $fromBound)
            ->where('orders.created_at', '<=', $toBound)
            ->whereNotNull('orders.serial_number')
            ->where('orders.serial_number', '!=', '')
            ->where(function (Builder $tx): void {
                $tx->whereNull('orders.transaction_id')->orWhere('orders.transaction_id', '');
            });

        self::constrainHasIngestedHardwareCommerce($query);

        return $query;
    }
}