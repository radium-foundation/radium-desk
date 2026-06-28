<?php

namespace App\Services\RadiumBox;

readonly class RadiumBoxOrderEnrichmentFetchResult
{
    public function __construct(
        public bool $retriable,
        public ?RadiumBoxOrderEnrichment $enrichment = null,
        public ?string $errorMessage = null,
        public ?string $errorType = null,
    ) {}

    public function succeeded(): bool
    {
        return ! $this->retriable && $this->errorMessage === null;
    }

    public function isNotFound(): bool
    {
        return $this->errorType === 'order_not_found';
    }
}
