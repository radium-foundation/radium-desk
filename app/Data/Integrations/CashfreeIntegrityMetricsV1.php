<?php

namespace App\Data\Integrations;

use Illuminate\Support\Carbon;

/**
 * Immutable Cashfree payment-integrity facts (v1).
 *
 * Projected from CashfreePaymentIntegrityService — no business rules.
 */
readonly class CashfreeIntegrityMetricsV1
{
    /**
     * @param  array<string, int>  $countsByCategory
     * @param  list<string>  $affectedOrderIds
     */
    public function __construct(
        public int $paidWithoutDeskOrderCount,
        public int $activeFailedWebhooks,
        public int $historicalResolvedFailures,
        public int $invalidEventFailures,
        public int $totalFailedWebhooks,
        public array $countsByCategory,
        public ?Carbon $oldestFailedAt,
        public ?Carbon $newestFailedAt,
        public array $affectedOrderIds,
        public bool $requiresAlert,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'paid_without_desk_order_count' => $this->paidWithoutDeskOrderCount,
            'active_failed_webhooks' => $this->activeFailedWebhooks,
            'historical_resolved_failures' => $this->historicalResolvedFailures,
            'invalid_event_failures' => $this->invalidEventFailures,
            'total_failed_webhooks' => $this->totalFailedWebhooks,
            'counts_by_category' => $this->countsByCategory,
            'oldest_failed_at' => $this->oldestFailedAt?->toIso8601String(),
            'newest_failed_at' => $this->newestFailedAt?->toIso8601String(),
            'affected_order_ids' => $this->affectedOrderIds,
            'requires_alert' => $this->requiresAlert,
        ];
    }
}
