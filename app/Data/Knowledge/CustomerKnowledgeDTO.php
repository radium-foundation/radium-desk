<?php

namespace App\Data\Knowledge;

use Illuminate\Support\Carbon;

readonly class CustomerKnowledgeDTO
{
    /**
     * @param  list<array{reference: string, title: string, status: string, created_at: Carbon|null}>  $previousIncidents
     * @param  list<array{reference: string, title: string, status: string, resolved_at: Carbon|null}>  $previousRepairs
     * @param  list<array{label: string, amount: float|null, occurred_at: Carbon|null}>  $previousPayments
     * @param  list<array{reference: string, title: string, created_at: Carbon|null}>  $previousEscalations
     * @param  list<string>  $repeatComplaints
     */
    public function __construct(
        public int $lifetimeOrderCount,
        public int $lifetimeRepairCount,
        public bool $isPremiumCustomer,
        public array $previousIncidents,
        public array $previousRepairs,
        public array $previousPayments,
        public array $previousEscalations,
        public array $repeatComplaints,
        public bool $repeatIssueDetected,
        public ?string $repeatIssueSummary,
        public float $repeatIssuePercentage,
        public ?float $averageRepairTurnaroundDays,
        public ?Carbon $lastInteractionAt,
        public ?string $lastInteractionSummary,
        public float $outstandingBalance,
        public string $paymentBehaviour,
        public string $warrantyHistorySummary,
    ) {}
}
