<?php

namespace App\Data;

use App\Enums\CashfreeWebhookFailureCategory;
use Illuminate\Support\Carbon;

readonly class CashfreeFailedWebhookClassificationReport
{
    /**
     * @param  array<string, int>  $countsByCategory
     * @param  list<string>  $affectedOrderIds
     * @param  list<CashfreeFailedWebhookRecord>  $records
     */
    public function __construct(
        public int $totalFailed,
        public int $activeFailedWebhooks,
        public int $historicalResolvedFailures,
        public int $invalidEventFailures,
        public array $countsByCategory,
        public ?Carbon $oldestFailedAt,
        public ?Carbon $newestFailedAt,
        public array $affectedOrderIds,
        public array $records,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_failed' => $this->totalFailed,
            'active_failed_webhooks' => $this->activeFailedWebhooks,
            'historical_resolved_failures' => $this->historicalResolvedFailures,
            'invalid_event_failures' => $this->invalidEventFailures,
            'counts_by_category' => $this->countsByCategory,
            'oldest_failed_at' => $this->oldestFailedAt?->toIso8601String(),
            'newest_failed_at' => $this->newestFailedAt?->toIso8601String(),
            'affected_order_ids' => $this->affectedOrderIds,
            'records' => array_map(
                fn (CashfreeFailedWebhookRecord $record): array => $record->toArray(),
                $this->records,
            ),
        ];
    }
}
