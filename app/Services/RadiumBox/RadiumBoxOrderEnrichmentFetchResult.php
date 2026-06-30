<?php

namespace App\Services\RadiumBox;

readonly class RadiumBoxOrderEnrichmentFetchResult
{
    public function __construct(
        public bool $retriable,
        public ?RadiumBoxOrderEnrichment $enrichment = null,
        public ?string $errorMessage = null,
        public ?string $errorType = null,
        public ?int $httpStatus = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public function succeeded(): bool
    {
        return ! $this->retriable && $this->errorMessage === null;
    }

    public function isNotFound(): bool
    {
        return $this->errorType === 'order_not_found';
    }

    public function isRateLimited(): bool
    {
        if ($this->errorType === 'rate_limited' || $this->httpStatus === 429) {
            return true;
        }

        $message = strtolower((string) $this->errorMessage);

        return str_contains($message, 'too many attempts')
            || str_contains($message, 'too many requests');
    }

    public function isTransientFailure(): bool
    {
        if ($this->succeeded() || $this->isNotFound() || $this->isRateLimited()) {
            return false;
        }

        if ($this->errorType === 'disabled') {
            return false;
        }

        if (in_array($this->errorType, ['connection_error', 'request_error'], true)) {
            return true;
        }

        if ($this->httpStatus !== null && $this->httpStatus >= 500) {
            return true;
        }

        $message = strtolower((string) $this->errorMessage);

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out');
    }
}
