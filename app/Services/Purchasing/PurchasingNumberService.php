<?php

namespace App\Services\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Support\Purchasing\PurchasingFinancialYear;
use Illuminate\Support\Carbon;

class PurchasingNumberService
{
    public function allocatePurchaseOrderNumber(?Carbon $at = null): string
    {
        $fy = PurchasingFinancialYear::containing($at ?? now());
        $prefix = $fy->purchaseOrderPrefix();

        $latestSequence = PurchaseOrder::query()
            ->where('po_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('po_number')
            ->map(static function (string $poNumber) use ($prefix): int {
                if (! str_starts_with($poNumber, $prefix)) {
                    return 0;
                }

                $suffix = substr($poNumber, strlen($prefix));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;

        $sequence = $latestSequence + 1;

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    public function allocateGoodsReceiptNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "GR-{$year}-";

        $latest = GoodsReceipt::query()
            ->where('receipt_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('receipt_number');

        $sequence = 1;
        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $matches) === 1) {
            $sequence = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
