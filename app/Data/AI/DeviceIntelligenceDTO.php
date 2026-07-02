<?php

namespace App\Data\AI;

readonly class DeviceIntelligenceDTO
{
    /**
     * @param  list<string>  $commonFailurePatterns
     * @param  list<string>  $partsFrequentlyReplaced
     */
    public function __construct(
        public ?string $model,
        public ?string $category,
        public ?string $variant,
        public bool $serialAvailable,
        public int $previousRepairsOnSerial,
        public int $previousRepairsOnModel,
        public array $commonFailurePatterns,
        public array $partsFrequentlyReplaced,
    ) {}
}
