<?php

namespace App\Services\RdService;

use App\Services\RadiumBox\RadiumBoxOrderEnrichment;

readonly class RdServiceFetchResult
{
    public const PROVIDER = 'rdservice';

    public function __construct(
        public bool $retriable,
        public bool $fallbackToAdmin,
        public ?RadiumBoxOrderEnrichment $enrichment = null,
        public ?string $errorMessage = null,
        public ?string $errorType = null,
        public ?int $httpStatus = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->enrichment !== null
            && ! $this->retriable
            && $this->errorMessage === null;
    }
}
