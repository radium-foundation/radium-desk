<?php

namespace App\Services\Purchasing;

use App\Models\GoodsReceipt;

class PurchasingNumberService
{
    public function __construct(
        private readonly PurchaseOrderReferenceService $purchaseOrderReferences,
    ) {}

    public function allocatePurchaseOrderNumber(): string
    {
        return $this->purchaseOrderReferences->allocate();
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
