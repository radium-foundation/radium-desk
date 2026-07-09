<?php

namespace App\Services\Bonvoice;

readonly class BonvoiceWebhookAuthResult
{
    private function __construct(
        private bool $valid,
        private ?string $error = null,
        private int $statusCode = 200,
    ) {}

    public static function valid(): self
    {
        return new self(valid: true);
    }

    public static function invalid(string $error, int $statusCode): self
    {
        return new self(valid: false, error: $error, statusCode: $statusCode);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
