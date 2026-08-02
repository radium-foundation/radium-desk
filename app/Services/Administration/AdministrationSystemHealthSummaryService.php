<?php

namespace App\Services\Administration;

use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformOverallHealthStatus;
use App\Services\Platform\Health\PlatformOverallHealthService;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Lightweight Administration System Health strip.
 *
 * Cache-only. Never probes PlatformHealthRegistry or integration diagnostics.
 */
class AdministrationSystemHealthSummaryService
{
    public const PLATFORM_OVERVIEW_CACHE_KEY = 'platform:health:overview';

    public function __construct(
        private readonly PlatformIntegrationHealthOverviewService $integrationOverview,
        private readonly PlatformOverallHealthService $overallHealth,
    ) {}

    /**
     * @return array{
     *     platform_status: string,
     *     platform_status_label: string,
     *     platform_healthy: bool,
     *     platform_available: bool,
     *     integration_status: string,
     *     integration_status_label: string,
     *     integration_available: bool,
     *     overall_status: string,
     *     overall_status_label: string,
     *     overall_available: bool,
     *     waiting_for_refresh: bool,
     *     last_updated_at: ?Carbon,
     *     last_updated_label: string,
     *     platform_url: string,
     *     platform_integrations_url: string,
     *     settings_url: string
     * }
     */
    public function summary(): array
    {
        $platform = $this->platformOverview();
        $integrations = $this->integrationOverview->cachedOverview();
        $overall = $this->overallHealth->summarize(useCache: true);

        $waiting = ! $platform['available'] && ! $integrations['available'] && ! $overall->available;

        $lastUpdated = $this->resolveLastUpdated(
            $platform['generated_at'] ?? null,
            $integrations['generated_at'] ?? null,
            $overall->updatedAt?->toIso8601String(),
        );

        return [
            'platform_status' => $platform['status'],
            'platform_status_label' => $platform['status_label'],
            'platform_healthy' => $platform['status'] === PlatformHealthStatus::Healthy->value,
            'platform_available' => $platform['available'],
            'integration_status' => $integrations['overall_status'],
            'integration_status_label' => $integrations['overall_status_label'],
            'integration_available' => $integrations['available'],
            'overall_status' => $overall->status->value,
            'overall_status_label' => $overall->statusLabel,
            'overall_available' => $overall->available,
            'waiting_for_refresh' => $waiting,
            'last_updated_at' => $lastUpdated,
            'last_updated_label' => $waiting
                ? 'Waiting for background refresh'
                : ($lastUpdated !== null
                    ? $lastUpdated->timezone(config('app.timezone'))->format('g:i A')
                    : 'Unavailable'),
            'platform_url' => route('admin.platform.index'),
            'platform_integrations_url' => route('admin.platform.index').'#platform-zone-integration_health',
            'settings_url' => route('admin.system-settings.index'),
        ];
    }

    /**
     * @return array{status: string, status_label: string, generated_at: ?string, available: bool}
     */
    private function platformOverview(): array
    {
        $cached = Cache::get(self::PLATFORM_OVERVIEW_CACHE_KEY);

        if (is_array($cached) && isset($cached['status'], $cached['status_label'])) {
            return [
                'status' => (string) $cached['status'],
                'status_label' => (string) $cached['status_label'],
                'generated_at' => isset($cached['generated_at']) ? (string) $cached['generated_at'] : null,
                'available' => true,
            ];
        }

        $overallCached = Cache::get(PlatformOverallHealthService::CACHE_KEY);
        if (is_array($overallCached) && isset($overallCached['status'], $overallCached['status_label'])) {
            $mapped = PlatformOverallHealthStatus::tryFrom((string) $overallCached['status']);

            return [
                'status' => $mapped === PlatformOverallHealthStatus::Unavailable
                    ? 'unavailable'
                    : (string) $overallCached['status'],
                'status_label' => (string) $overallCached['status_label'],
                'generated_at' => isset($overallCached['updated_at']) ? (string) $overallCached['updated_at'] : null,
                'available' => (bool) ($overallCached['available'] ?? false),
            ];
        }

        return [
            'status' => 'unavailable',
            'status_label' => 'Unavailable',
            'generated_at' => null,
            'available' => false,
        ];
    }

    private function resolveLastUpdated(?string ...$values): ?Carbon
    {
        $times = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            try {
                $times[] = Carbon::parse($value);
            } catch (\Throwable) {
                //
            }
        }

        if ($times === []) {
            return null;
        }

        usort($times, static fn (Carbon $a, Carbon $b): int => $b <=> $a);

        return $times[0];
    }
}
