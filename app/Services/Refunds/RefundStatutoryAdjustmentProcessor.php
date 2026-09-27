<?php

namespace App\Services\Refunds;

use App\Exceptions\Refunds\RefundStatutoryAdjustmentPermanentFailureException;
use App\Models\OutboxEvent;

final class RefundStatutoryAdjustmentProcessor
{
    public function __construct(
        private readonly RefundStatutoryAdjustmentService $adjustments,
    ) {}

    public function process(OutboxEvent $event): void
    {
        $payload = $event->payload ?? [];
        $adjustmentId = (int) ($payload['refund_statutory_adjustment_id'] ?? 0);
        $refundRequestId = (int) ($payload['refund_request_id'] ?? 0);

        if ($adjustmentId <= 0 && $refundRequestId <= 0) {
            throw new RefundStatutoryAdjustmentPermanentFailureException(
                'Refund statutory adjustment outbox event is missing adjustment identifiers.',
            );
        }

        if ($adjustmentId <= 0) {
            throw new RefundStatutoryAdjustmentPermanentFailureException(
                'Refund statutory adjustment outbox event is missing refund_statutory_adjustment_id.',
            );
        }

        $this->adjustments->processOutboxEvent($adjustmentId);
    }
}
