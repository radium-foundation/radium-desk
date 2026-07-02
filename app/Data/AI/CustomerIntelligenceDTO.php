<?php

namespace App\Data\AI;

use Illuminate\Support\Carbon;

readonly class CustomerIntelligenceDTO
{
    public function __construct(
        public int $lifetimeOrderCount,
        public int $lifetimeRepairCount,
        public bool $isPremiumCustomer,
        public string $warrantyHistorySummary,
        public bool $repeatIssueDetected,
        public ?string $repeatIssueSummary,
        public ?float $averageRepairTurnaroundDays,
        public ?Carbon $lastInteractionAt,
        public ?string $lastInteractionSummary,
        public float $outstandingBalance,
        public string $paymentBehaviour,
    ) {}
}
