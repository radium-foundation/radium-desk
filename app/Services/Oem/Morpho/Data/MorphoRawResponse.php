<?php

namespace App\Services\Oem\Morpho\Data;

final class MorphoRawResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?array $json,
        public readonly ?string $body = null,
        public readonly bool $timedOut = false,
    ) {}

    public function succeeded(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300 && is_array($this->json);
    }
}
