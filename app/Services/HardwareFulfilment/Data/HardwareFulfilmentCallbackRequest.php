<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareFulfilmentCallbackRequest
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $url,
        public readonly string $rawBody,
        public readonly array $payload,
        public readonly array $headers,
    ) {}
}
