<?php

namespace App\Services\Platform\Alerts\Contributors;

use App\Contracts\Platform\PlatformAlertContributor;
use App\Data\Platform\PlatformAlert;
use App\Enums\IntegrationHealthStatus;
use App\Enums\PlatformAlertSeverity;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use Illuminate\Support\Carbon;

/**
 * Cache-only alerts from Integration Health item caches.
 */
class IntegrationHealthAlertContributor implements PlatformAlertContributor
{
    public function __construct(
        private readonly PlatformIntegrationHealthOverviewService $integrations,
    ) {}

    public function key(): string
    {
        return 'integration_health';
    }

    public function label(): string
    {
        return 'Integration Health';
    }

    public function sortOrder(): int
    {
        return 20;
    }

    public function alerts(): array
    {
        $alerts = [];
        $overview = $this->integrations->cachedOverview();

        foreach ($overview['items'] as $item) {
            if (! is_array($item) || ! isset($item['key'], $item['status'])) {
                continue;
            }

            $status = IntegrationHealthStatus::tryFrom((string) $item['status'])
                ?? IntegrationHealthStatus::Unavailable;
            $severity = PlatformAlertSeverity::fromIntegration($status);

            if (! in_array($severity, [
                PlatformAlertSeverity::Critical,
                PlatformAlertSeverity::Warning,
                PlatformAlertSeverity::Information,
            ], true)) {
                continue;
            }

            $key = (string) $item['key'];
            $label = (string) ($item['label'] ?? $key);
            $updatedAt = null;
            if (! empty($item['updated_at']) && is_string($item['updated_at'])) {
                try {
                    $updatedAt = Carbon::parse($item['updated_at']);
                } catch (\Throwable) {
                    $updatedAt = null;
                }
            }

            $alerts[] = new PlatformAlert(
                id: 'integration:'.$key.':'.$status->value,
                source: $this->key(),
                groupKey: $key,
                title: $label,
                summary: (string) ($item['summary'] ?? $item['detail'] ?? $status->label()),
                severity: $severity,
                status: $status->label(),
                lastUpdated: $updatedAt,
                count: 1,
                link: route('admin.platform.index').'#platform-zone-integration_health',
            );
        }

        return $alerts;
    }
}
