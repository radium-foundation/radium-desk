<?php

namespace App\Services\RadiumBox;

readonly class RadiumBoxOrderEnrichment
{
    public function __construct(
        public ?string $serialNumber = null,
        public ?string $deviceModel = null,
    ) {}

    public function hasData(): bool
    {
        return filled($this->serialNumber) || filled($this->deviceModel);
    }
}
