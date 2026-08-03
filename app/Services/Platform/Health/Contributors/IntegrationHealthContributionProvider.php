<?php

namespace App\Services\Platform\Health\Contributors;

use App\Contracts\Platform\PlatformHealthContributor;
use App\Data\Platform\PlatformHealthContribution;
use App\Enums\IntegrationHealthStatus;
use App\Enums\PlatformOverallHealthStatus;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use Illuminate\Support\Carbon;

/**
 * Feeds Integration Health into Overall Mission Health.
 *
 * NotConfigured / Disabled items are configuration state — they remain visible
 * on the Integration Health zone but are excluded from Mission Health scoring.
 */
class IntegrationHealthContributionProvider implements PlatformHealthContributor
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

    public function contribute(): ?PlatformHealthContribution
    {
        $overview = $this->integrations->cachedOverview();

        $updatedAt = null;
        if (! empty($overview['generated_at']) && is_string($overview['generated_at'])) {
            try {
                $updatedAt = Carbon::parse($overview['generated_at']);
            } catch (\Throwable) {
                $updatedAt = null;
            }
        }

        if (! (bool) ($overview['available'] ?? false)) {
            return new PlatformHealthContribution(
                source: $this->key(),
                label: $this->label(),
                status: PlatformOverallHealthStatus::Unavailable,
                available: false,
                updatedAt: $updatedAt,
                stale: false,
                weight: 2,
            );
        }

        $scorable = $this->scorableOverallStatuses($overview['items'] ?? []);

        // All items are NotConfigured/Disabled (or empty) — exclude from Mission denominator.
        if ($scorable === []) {
            return new PlatformHealthContribution(
                source: $this->key(),
                label: $this->label(),
                status: PlatformOverallHealthStatus::Unavailable,
                available: false,
                updatedAt: $updatedAt,
                stale: false,
                weight: 2,
            );
        }

        return new PlatformHealthContribution(
            source: $this->key(),
            label: $this->label(),
            status: PlatformOverallHealthStatus::worst(...$scorable),
            available: true,
            updatedAt: $updatedAt,
            stale: false,
            weight: 2,
        );
    }

    /**
     * Map configured/live integration item statuses for Mission Health only.
     * Skips NotConfigured and Disabled (configuration state).
     *
     * @param  list<mixed>  $items
     * @return list<PlatformOverallHealthStatus>
     */
    private function scorableOverallStatuses(array $items): array
    {
        $statuses = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['status'])) {
                continue;
            }

            $status = IntegrationHealthStatus::tryFrom((string) $item['status']);
            if ($status === null) {
                continue;
            }

            if (in_array($status, [
                IntegrationHealthStatus::NotConfigured,
                IntegrationHealthStatus::Disabled,
            ], true)) {
                continue;
            }

            $statuses[] = match ($status) {
                IntegrationHealthStatus::Healthy => PlatformOverallHealthStatus::Healthy,
                IntegrationHealthStatus::Warning, IntegrationHealthStatus::Loading => PlatformOverallHealthStatus::Warning,
                IntegrationHealthStatus::Critical, IntegrationHealthStatus::Unavailable => PlatformOverallHealthStatus::Critical,
                IntegrationHealthStatus::Disabled, IntegrationHealthStatus::NotConfigured => PlatformOverallHealthStatus::Unavailable,
            };
        }

        return $statuses;
    }
}
