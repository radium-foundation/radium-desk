<?php

namespace App\Services\Platform\Health\Contributors;

use App\Contracts\Platform\PlatformHealthContributor;
use App\Data\Platform\PlatformHealthContribution;
use App\Enums\IntegrationHealthStatus;
use App\Enums\PlatformOverallHealthStatus;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use Illuminate\Support\Carbon;

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
        $status = IntegrationHealthStatus::tryFrom((string) $overview['overall_status'])
            ?? IntegrationHealthStatus::Loading;

        $updatedAt = null;
        if (! empty($overview['generated_at']) && is_string($overview['generated_at'])) {
            try {
                $updatedAt = Carbon::parse($overview['generated_at']);
            } catch (\Throwable) {
                $updatedAt = null;
            }
        }

        $overall = match ($status) {
            IntegrationHealthStatus::Healthy => PlatformOverallHealthStatus::Healthy,
            IntegrationHealthStatus::Warning, IntegrationHealthStatus::Loading => PlatformOverallHealthStatus::Warning,
            IntegrationHealthStatus::Critical, IntegrationHealthStatus::Unavailable => PlatformOverallHealthStatus::Critical,
            IntegrationHealthStatus::Disabled, IntegrationHealthStatus::NotConfigured => PlatformOverallHealthStatus::Unavailable,
        };

        return new PlatformHealthContribution(
            source: $this->key(),
            label: $this->label(),
            status: $overall,
            available: (bool) $overview['available'],
            updatedAt: $updatedAt,
            stale: false,
            weight: 2,
        );
    }
}
