<?php

namespace App\Data\Platform;

use App\Enums\PlatformHealthStatus;
use Illuminate\Support\Carbon;

/**
 * Single shared infra health object for Platform Health, Critical Alerts,
 * Overall Health, and Watchdog/Telegram consumers.
 */
readonly class PlatformHealthSnapshot
{
    /**
     * @param  list<PlatformHealthComponent>  $components
     */
    public function __construct(
        public PlatformHealthStatus $status,
        public string $statusLabel,
        public array $components,
        public Carbon $generatedAt,
        public bool $stale = false,
        public bool $available = true,
    ) {}

    public function component(string $key): ?PlatformHealthComponent
    {
        foreach ($this->components as $component) {
            if ($component->key === $key) {
                return $component;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->statusLabel,
            'components' => array_map(
                static fn (PlatformHealthComponent $component): array => $component->toArray(),
                $this->components,
            ),
            'generated_at' => $this->generatedAt->toIso8601String(),
            'stale' => $this->stale,
            'available' => $this->available,
        ];
    }

    /**
     * Thin Administration overview payload (status only).
     *
     * @return array{status: string, status_label: string, generated_at: string}
     */
    public function toOverviewArray(): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->statusLabel,
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        if (! isset($payload['status'])) {
            return null;
        }

        $status = PlatformHealthStatus::tryFrom((string) $payload['status']);
        if ($status === null) {
            return null;
        }

        $generatedAt = now();
        if (! empty($payload['generated_at']) && is_string($payload['generated_at'])) {
            try {
                $generatedAt = Carbon::parse($payload['generated_at']);
            } catch (\Throwable) {
                $generatedAt = now();
            }
        }

        $components = [];
        foreach ($payload['components'] ?? [] as $row) {
            if (! is_array($row) || ! isset($row['key'], $row['status'])) {
                continue;
            }

            $componentStatus = PlatformHealthStatus::tryFrom((string) $row['status']);
            if ($componentStatus === null) {
                continue;
            }

            $checkedAt = $generatedAt;
            if (! empty($row['checked_at']) && is_string($row['checked_at'])) {
                try {
                    $checkedAt = Carbon::parse($row['checked_at']);
                } catch (\Throwable) {
                    $checkedAt = $generatedAt;
                }
            }

            $components[] = new PlatformHealthComponent(
                key: (string) $row['key'],
                label: (string) ($row['label'] ?? $row['key']),
                status: $componentStatus,
                detail: (string) ($row['detail'] ?? ''),
                checkedAt: $checkedAt,
                metrics: is_array($row['metrics'] ?? null) ? $row['metrics'] : [],
            );
        }

        return new self(
            status: $status,
            statusLabel: (string) ($payload['status_label'] ?? $status->label()),
            components: $components,
            generatedAt: $generatedAt,
            stale: (bool) ($payload['stale'] ?? false),
            available: (bool) ($payload['available'] ?? true),
        );
    }
}
