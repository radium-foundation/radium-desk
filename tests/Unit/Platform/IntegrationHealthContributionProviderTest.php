<?php

namespace Tests\Unit\Platform;

use App\Enums\IntegrationHealthStatus;
use App\Enums\PlatformOverallHealthStatus;
use App\Services\Platform\Health\Contributors\IntegrationHealthContributionProvider;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use Mockery;
use Tests\TestCase;

class IntegrationHealthContributionProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_not_configured_integrations_are_excluded_from_mission_scoring(): void
    {
        $provider = $this->makeProvider([
            $this->item('cashfree', IntegrationHealthStatus::Healthy),
            $this->item('gmail', IntegrationHealthStatus::Healthy),
            $this->item('telegram', IntegrationHealthStatus::Healthy),
            $this->item('meta_flow', IntegrationHealthStatus::NotConfigured),
        ]);

        $contribution = $provider->contribute();

        $this->assertNotNull($contribution);
        $this->assertTrue($contribution->available);
        $this->assertSame(PlatformOverallHealthStatus::Healthy, $contribution->status);
        $this->assertSame(2, $contribution->weight);
    }

    public function test_disabled_integrations_are_excluded_from_mission_scoring(): void
    {
        $provider = $this->makeProvider([
            $this->item('interakt', IntegrationHealthStatus::Healthy),
            $this->item('telegram', IntegrationHealthStatus::Disabled),
        ]);

        $contribution = $provider->contribute();

        $this->assertNotNull($contribution);
        $this->assertTrue($contribution->available);
        $this->assertSame(PlatformOverallHealthStatus::Healthy, $contribution->status);
    }

    public function test_live_critical_still_reduces_mission_contribution(): void
    {
        $provider = $this->makeProvider([
            $this->item('cashfree', IntegrationHealthStatus::Critical),
            $this->item('meta_flow', IntegrationHealthStatus::NotConfigured),
        ]);

        $contribution = $provider->contribute();

        $this->assertNotNull($contribution);
        $this->assertTrue($contribution->available);
        $this->assertSame(PlatformOverallHealthStatus::Critical, $contribution->status);
    }

    public function test_only_configuration_states_exclude_contribution_from_denominator(): void
    {
        $provider = $this->makeProvider([
            $this->item('meta_flow', IntegrationHealthStatus::NotConfigured),
            $this->item('telegram', IntegrationHealthStatus::Disabled),
        ]);

        $contribution = $provider->contribute();

        $this->assertNotNull($contribution);
        $this->assertFalse($contribution->available);
        $this->assertSame(PlatformOverallHealthStatus::Unavailable, $contribution->status);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function makeProvider(array $items): IntegrationHealthContributionProvider
    {
        $integrations = Mockery::mock(PlatformIntegrationHealthOverviewService::class);
        $integrations->shouldReceive('cachedOverview')->andReturn([
            'items' => $items,
            'overall_status' => IntegrationHealthStatus::NotConfigured->value,
            'overall_status_label' => 'Not Configured',
            'generated_at' => now()->toIso8601String(),
            'available' => true,
        ]);

        return new IntegrationHealthContributionProvider($integrations);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $key, IntegrationHealthStatus $status): array
    {
        return [
            'key' => $key,
            'label' => $key,
            'status' => $status->value,
            'status_label' => $status->label(),
            'available' => true,
        ];
    }
}
