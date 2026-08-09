<?php

namespace App\Data;

use App\Enums\CashfreeMissedBatchHealDisposition;

readonly class CashfreeMissedBatchHealOrderResult
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public string $orderId,
        public CashfreeMissedBatchHealDisposition $disposition,
        public string $reason,
        public ?string $cfPaymentId = null,
        public ?array $payload = null,
        public ?int $webhookLogId = null,
        public ?int $deskOrderId = null,
        public ?string $expectedSerial = null,
    ) {}
}
