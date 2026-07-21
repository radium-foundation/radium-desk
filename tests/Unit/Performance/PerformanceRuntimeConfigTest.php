<?php

namespace Tests\Unit\Performance;

use App\Services\Performance\PerformanceRuntimeConfig;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceRuntimeConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_reads_balanced_defaults_from_seeded_settings(): void
    {
        $config = app(PerformanceRuntimeConfig::class);

        $this->assertSame(30000, $config->dashboardPollIntervalMs());
        $this->assertSame(20000, $config->notificationPollIntervalMs());
        $this->assertSame(120, $config->presenceHeartbeatIntervalSeconds());
    }

    public function test_reflects_runtime_setting_changes(): void
    {
        $settings = app(SystemSettingsService::class);
        $user = \App\Models\User::factory()->create();

        $settings->set('performance.polling.dashboard_live_ms', 45000, $user);

        $this->assertSame(45000, app(PerformanceRuntimeConfig::class)->dashboardPollIntervalMs());
    }

    public function test_for_blade_returns_expected_keys(): void
    {
        $payload = app(PerformanceRuntimeConfig::class)->forBlade();

        $this->assertArrayHasKey('dashboardPollIntervalMs', $payload);
        $this->assertArrayHasKey('notificationPollIntervalMs', $payload);
        $this->assertArrayHasKey('presenceHeartbeatIntervalSeconds', $payload);
        $this->assertArrayHasKey('executiveDashboardPollIntervalSeconds', $payload);
    }
}
