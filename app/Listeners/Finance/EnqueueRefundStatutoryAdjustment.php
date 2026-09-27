<?php

namespace App\Listeners\Finance;

use App\Events\Finance\RefundCompleted;
use App\Services\Refunds\RefundStatutoryAdjustmentService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class EnqueueRefundStatutoryAdjustment
{
    public function __construct(
        private readonly RefundStatutoryAdjustmentService $adjustments,
    ) {}

    public function handle(RefundCompleted $event): void
    {
        if (! config('refunds.statutory_adjustment.enabled', false)) {
            return;
        }

        try {
            $this->adjustments->enqueueFromRefundCompleted($event->refund);
        } catch (Throwable $exception) {
            Log::error('[Refund] Statutory adjustment enqueue listener failed.', [
                'refund_id' => $event->refund->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
