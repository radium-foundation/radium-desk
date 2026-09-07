<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwarePaymentEvidenceDraft
{
    public function __construct(
        public readonly string $sourceId,
        public readonly ?string $cashfreePaymentId,
        public readonly string $paymentStatus,
        public readonly ?int $supportOrderId = null,
        public readonly ?string $merchantOrderId = null,
        public readonly ?string $cfOrderId = null,
        public readonly ?string $gatewayOrderId = null,
        public readonly ?string $gatewayPaymentId = null,
        public readonly ?string $bankReference = null,
        public readonly ?float $paymentAmount = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $paidAt = null,
        public readonly ?int $cashfreeWebhookLogId = null,
        public readonly array $metadata = [],
    ) {}
}
