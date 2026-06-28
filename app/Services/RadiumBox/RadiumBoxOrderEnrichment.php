<?php

namespace App\Services\RadiumBox;

readonly class RadiumBoxOrderEnrichment
{
    public function __construct(
        public ?string $serialNumber = null,
        public ?string $deviceModel = null,
        public ?string $activationYear = null,
        public ?string $warranty = null,
        public ?string $amc = null,
    ) {}

    public function hasData(): bool
    {
        return filled($this->serialNumber)
            || filled($this->deviceModel)
            || filled($this->activationYear)
            || filled($this->warranty)
            || filled($this->amc);
    }

    /**
     * @return array<string, string>
     */
    public function supplementalMetadata(): array
    {
        return array_filter([
            'activation_year' => $this->activationYear,
            'warranty' => $this->warranty,
            'amc' => $this->amc,
        ], fn (?string $value): bool => filled($value));
    }
}
