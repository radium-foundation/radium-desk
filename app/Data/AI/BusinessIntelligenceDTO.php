<?php

namespace App\Data\AI;

readonly class BusinessIntelligenceDTO
{
    public function __construct(
        public float $revenueFromCustomer,
        public float $warrantyCost,
        public float $totalRepairValue,
        public ?string $amcServicePlan,
        public float $partsCostHistory,
    ) {}
}
