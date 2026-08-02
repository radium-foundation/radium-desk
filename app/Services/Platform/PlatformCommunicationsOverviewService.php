<?php

namespace App\Services\Platform;

use App\Enums\IntegrationHealthStatus;
use App\Enums\PlatformHealthStatus;
use App\Services\Operations\OperationsRecentNotificationFailuresService;
use Illuminate\Support\Facades\Cache;

/**
 * Operational communication status — not Integration Health diagnostics.
 */
class PlatformCommunicationsOverviewService
{
    public const CACHE_KEY = PlatformCachePolicy::KEY_COMMUNICATIONS_OVERVIEW;

    public const CACHE_TTL_SECONDS = PlatformCachePolicy::TTL_PRIORITY_3;

    public function __construct(
        private readonly PlatformIntegrationHealthOverviewService $integrations,
        private readonly OperationsRecentNotificationFailuresService $recentFailures,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: ?string, available: bool, links: list<array{label: string, url: string}>}
     */
    public function cachedOverview(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['items'])) {
            return $cached + ['available' => true];
        }

        // Derive from integration item caches when warm (no diagnostics).
        $integration = $this->integrations->cachedOverview();
        if ($integration['available']) {
            return $this->fromIntegrationItems($integration['items'], $integration['generated_at'] ?? null, write: false);
        }

        return [
            'items' => [],
            'overall_status' => PlatformHealthStatus::Disabled->value,
            'generated_at' => null,
            'available' => false,
            'links' => $this->links(),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: string, available: bool, links: list<array{label: string, url: string}>}
     */
    public function overview(bool $useCache = true): array
    {
        if ($useCache) {
            $cached = $this->cachedOverview();
            if ($cached['available']) {
                return $cached;
            }
        }

        // Prefer Integration Health caches (warmed first). Re-probe only when cold.
        $integration = $this->integrations->cachedOverview();
        if (! $integration['available']) {
            $integration = $this->integrations->overview(useCache: false);
        }

        return $this->fromIntegrationItems(
            $integration['items'],
            $integration['generated_at'] ?? now()->toIso8601String(),
            write: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(string $key): array
    {
        if ($key === 'notification_failures') {
            return [
                'key' => 'notification_failures',
                'label' => 'Recent Notification Failures',
                'partial' => 'admin.operations.partials.recent-notification-failures',
                'failures' => $this->recentFailures->recent(),
            ];
        }

        abort(404);
    }

    public function isKnownKey(string $key): bool
    {
        return $key === 'notification_failures';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: ?string, available: bool, links: list<array{label: string, url: string}>}
     */
    private function fromIntegrationItems(array $items, ?string $generatedAt, bool $write): array
    {
        $channelKeys = ['gmail', 'interakt', 'telegram', 'zeptomail'];
        $mapped = [];
        $statuses = [];

        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if (! in_array($key, $channelKeys, true)) {
                continue;
            }

            $integrationStatus = IntegrationHealthStatus::tryFrom((string) ($item['status'] ?? ''))
                ?? IntegrationHealthStatus::Unavailable;
            $platform = $integrationStatus->toPlatform();
            $statuses[] = $platform;

            $mapped[] = [
                'key' => $key,
                'label' => (string) ($item['label'] ?? $key),
                'status' => $integrationStatus->value,
                'status_label' => $integrationStatus->label(),
                'badge_class' => $integrationStatus->badgeClass(),
                'summary' => (string) ($item['summary'] ?? $item['detail'] ?? ''),
                'updated_at' => $item['updated_at'] ?? $generatedAt,
            ];
        }

        $mapped[] = [
            'key' => 'notification_failures',
            'label' => 'Notification Failures',
            'status' => 'information',
            'status_label' => 'Operational',
            'badge_class' => 'info',
            'summary' => 'Expand for recent notification failures (last 7 days).',
            'updated_at' => $generatedAt,
            'expandable' => true,
        ];

        $overall = $statuses === []
            ? PlatformHealthStatus::Disabled
            : PlatformHealthStatus::worst(...$statuses);

        $payload = [
            'items' => $mapped,
            'overall_status' => $overall->value,
            'generated_at' => $generatedAt ?? now()->toIso8601String(),
            'available' => $mapped !== [],
            'links' => $this->links(),
        ];

        if ($write) {
            Cache::put(self::CACHE_KEY, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));
        }

        return $payload;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function links(): array
    {
        return [
            [
                'label' => 'Integration Health (diagnostics)',
                'url' => route('admin.platform.index').'#platform-zone-integration_health',
            ],
            [
                'label' => 'Audit Logs',
                'url' => route('audit-logs.index'),
            ],
        ];
    }
}
