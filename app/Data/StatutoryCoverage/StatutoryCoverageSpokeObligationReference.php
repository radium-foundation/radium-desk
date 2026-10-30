<?php

namespace App\Data\StatutoryCoverage;

final readonly class StatutoryCoverageSpokeObligationReference
{
    public function __construct(
        public string $channel,
        public string $sourceType,
        public string $sourceId,
        public ?string $obligationClass,
    ) {}

    public function identityKey(): string
    {
        return strtoupper($this->channel).'|'.$this->sourceType.'|'.strtoupper(trim($this->sourceId));
    }
}
