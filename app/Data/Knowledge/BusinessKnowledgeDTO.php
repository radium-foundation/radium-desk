<?php

namespace App\Data\Knowledge;

readonly class BusinessKnowledgeDTO
{
    /**
     * @param  list<array{plan: string, status: string}>  $amcHistory
     */
    public function __construct(
        public float $customerLifetimeValue,
        public float $profitability,
        public float $warrantyCost,
        public float $repeatRevenue,
        public float $totalRepairValue,
        public float $partsCostHistory,
        public array $amcHistory,
    ) {}
}
