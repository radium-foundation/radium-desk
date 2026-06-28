<?php

namespace App\Infrastructure\IntegrationHealth;

use Carbon\CarbonInterface;

/**
 * Health snapshot for a single integration.
 *
 * Designed for future dashboard and alerting surfaces; no UI dependency.
 */
readonly class IntegrationHealthSnapshot
{
    public function __construct(
        public string $key,
        public string $label,
        public string $connectionStatus,
        public ?CarbonInterface $lastSuccessAt,
        public ?CarbonInterface $lastFailureAt,
        public ?CarbonInterface $lastSyncAt,
        public int $retryCount,
        public ?float $averageResponseTimeMs,
        public ?string $lastErrorMessage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'connection_status' => $this->connectionStatus,
            'last_success_at' => $this->lastSuccessAt?->toIso8601String(),
            'last_failure_at' => $this->lastFailureAt?->toIso8601String(),
            'last_sync_at' => $this->lastSyncAt?->toIso8601String(),
            'retry_count' => $this->retryCount,
            'average_response_time_ms' => $this->averageResponseTimeMs,
            'last_error_message' => $this->lastErrorMessage,
        ];
    }
}
