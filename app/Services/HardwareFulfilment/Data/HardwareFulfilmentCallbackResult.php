<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareFulfilmentCallbackResult
{
    public function __construct(
        public readonly string $status,
        public readonly bool $accepted,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?string $error = null,
    ) {}
}
