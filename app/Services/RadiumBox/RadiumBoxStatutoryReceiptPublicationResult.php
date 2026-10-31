<?php

namespace App\Services\RadiumBox;

final class RadiumBoxStatutoryReceiptPublicationResult
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?string $boxReceiptId,
        public readonly bool $duplicateDelivery,
        public readonly ?string $eligibilityCode,
        public readonly ?string $message,
        public readonly bool $retryable,
    ) {}
}
