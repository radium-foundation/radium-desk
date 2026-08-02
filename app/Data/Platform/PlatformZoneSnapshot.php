<?php

namespace App\Data\Platform;

use App\Enums\PlatformHealthStatus;
use Illuminate\Support\Carbon;

readonly class PlatformZoneSnapshot
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public string $key,
        public PlatformHealthStatus $status,
        public string $statusLabel,
        public ?Carbon $updatedAt,
        public array $summary,
        public string $html,
        public bool $fromCache = false,
        public bool $available = true,
        public bool $stale = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel,
            'updated_at' => $this->updatedAt?->toIso8601String(),
            'summary' => $this->summary,
            'html' => $this->html,
            'from_cache' => $this->fromCache,
            'available' => $this->available,
            'stale' => $this->stale,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCachePayload(string $key, array $payload): ?self
    {
        if (! isset($payload['status'], $payload['html'])) {
            return null;
        }

        $status = PlatformHealthStatus::tryFrom((string) $payload['status'])
            ?? PlatformHealthStatus::Disabled;

        $updatedAt = null;
        if (! empty($payload['updated_at']) && is_string($payload['updated_at'])) {
            try {
                $updatedAt = Carbon::parse($payload['updated_at']);
            } catch (\Throwable) {
                $updatedAt = null;
            }
        }

        return new self(
            key: $key,
            status: $status,
            statusLabel: (string) ($payload['status_label'] ?? $status->label()),
            updatedAt: $updatedAt,
            summary: is_array($payload['summary'] ?? null) ? $payload['summary'] : [],
            html: (string) $payload['html'],
            fromCache: true,
            available: (bool) ($payload['available'] ?? true),
            stale: (bool) ($payload['stale'] ?? false),
        );
    }
}
