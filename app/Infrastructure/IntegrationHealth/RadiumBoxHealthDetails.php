<?php

namespace App\Infrastructure\IntegrationHealth;

use Carbon\CarbonInterface;

readonly class RadiumBoxHealthDetails
{
    public function __construct(
        public ?CarbonInterface $lastSuccessfulSyncAt,
        public int $failedSyncs,
        public int $pendingSyncs,
        public ?float $averageResponseTimeMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'last_successful_sync_at' => $this->lastSuccessfulSyncAt?->toIso8601String(),
            'failed_syncs' => $this->failedSyncs,
            'pending_syncs' => $this->pendingSyncs,
            'average_response_time_ms' => $this->averageResponseTimeMs,
        ];
    }
}
