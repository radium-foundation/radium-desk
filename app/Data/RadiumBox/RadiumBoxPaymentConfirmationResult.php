<?php

namespace App\Data\RadiumBox;

final class RadiumBoxPaymentConfirmationResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly ?string $gatewayOrderId = null,
        public readonly ?string $businessOrderId = null,
        public readonly ?string $paymentStatus = null,
        public readonly bool $firstPaid = false,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorMessage = null,
        public readonly ?array $handoff = null,
        public readonly bool $retriable = false,
    ) {}

    public function handoffEnqueued(): bool
    {
        return is_array($this->handoff) && ($this->handoff['id'] ?? null) !== null;
    }
}
