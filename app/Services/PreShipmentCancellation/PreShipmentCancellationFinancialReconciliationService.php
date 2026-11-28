<?php

namespace App\Services\PreShipmentCancellation;

use App\Enums\RefundStatus;
use App\Models\PreShipmentCancellationRequest;
use App\Models\RefundRequest;
use App\Services\RefundCalculationService;
use Illuminate\Support\Facades\DB;

/**
 * Keeps PSC financial snapshots aligned with canonical completed refund records.
 */
final class PreShipmentCancellationFinancialReconciliationService
{
    public function __construct(
        private readonly RefundCalculationService $calculations,
    ) {}

    public function syncAlreadyRefundedFromLinkedRefund(PreShipmentCancellationRequest $case): PreShipmentCancellationRequest
    {
        $case = $case->fresh();
        if ($case === null || $case->refund_request_id === null) {
            return $case;
        }

        $refund = RefundRequest::query()->find($case->refund_request_id);
        if ($refund === null || ! in_array($refund->status, [RefundStatus::Completed, RefundStatus::Closed], true)) {
            return $case;
        }

        $order = $case->order ?? $case->order()->first();
        if ($order === null) {
            return $case;
        }

        $alreadyRefunded = round($this->calculations->alreadyRefundedAmount($order), 2);
        if ((float) $case->already_refunded_amount === $alreadyRefunded) {
            return $case;
        }

        return DB::transaction(function () use ($case, $alreadyRefunded): PreShipmentCancellationRequest {
            $locked = PreShipmentCancellationRequest::query()
                ->whereKey($case->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill(['already_refunded_amount' => $alreadyRefunded])->save();

            return $locked->fresh() ?? $locked;
        });
    }
}
