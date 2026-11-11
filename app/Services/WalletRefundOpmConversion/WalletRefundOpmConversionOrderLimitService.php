<?php

namespace App\Services\WalletRefundOpmConversion;

use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\Order;
use App\Models\WalletRefundOpmConversion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WalletRefundOpmConversionOrderLimitService
{
    public function committedAmountForOrder(Order $order): float
    {
        if (! Schema::hasTable('wallet_refund_opm_conversions')) {
            return 0.0;
        }

        $statuses = array_map(
            fn (WalletRefundOpmConversionStatus $status): string => $status->value,
            array_filter(
                WalletRefundOpmConversionStatus::cases(),
                fn (WalletRefundOpmConversionStatus $status): bool => $status->countsTowardOrderRefundLimit(),
            ),
        );

        return round((float) WalletRefundOpmConversion::query()
            ->whereIn('status', $statuses)
            ->whereIn('refund_request_id', $order->refundRequests()->select('id'))
            ->sum(DB::raw('amount')), 2);
    }
}
