<?php

namespace Tests\Unit\Performance;

use App\Services\Performance\PerformanceSettingsService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private PerformanceSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);

        $this->service = app(PerformanceSettingsService::class);
    }

    public function test_high_performance_profile_overwrites_polling_values_on_save(): void
    {
        $submitted = $this->baseSubmittedSettings();
        $submitted['performance.profile'] = 'high_performance';
        $preset = $this->service->presetValues('high_performance');

        foreach ($preset as $key => $value) {
            $submitted[$key] = $value;
        }

        $resolved = $this->service->resolveForSave($submitted);

        $this->assertSame('high_performance', $resolved['performance.profile']);
        $this->assertSame(15000, $resolved['performance.polling.dashboard_live_ms']);
        $this->assertSame(10000, $resolved['performance.polling.notification_ms']);
        $this->assertSame(60, $resolved['performance.polling.presence_heartbeat_seconds']);
    }

    public function test_manual_profile_preserves_custom_polling_values(): void
    {
        $submitted = $this->baseSubmittedSettings();
        $submitted['performance.profile'] = 'manual';
        $submitted['performance.polling.dashboard_live_ms'] = 45000;

        $resolved = $this->service->resolveForSave($submitted);

        $this->assertSame('manual', $resolved['performance.profile']);
        $this->assertSame(45000, $resolved['performance.polling.dashboard_live_ms']);
    }

    public function test_preset_drift_switches_profile_to_manual(): void
    {
        $submitted = $this->baseSubmittedSettings();
        $submitted['performance.profile'] = 'balanced';
        $submitted['performance.polling.dashboard_live_ms'] = 45000;

        $resolved = $this->service->resolveForSave($submitted);

        $this->assertSame('manual', $resolved['performance.profile']);
        $this->assertSame(45000, $resolved['performance.polling.dashboard_live_ms']);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseSubmittedSettings(): array
    {
        $settings = app(SystemSettingsService::class);
        $submitted = [];

        foreach (config('system_settings.settings', []) as $key => $definition) {
            $submitted[$key] = $settings->get($key, $definition['default'] ?? null);
        }

        return $submitted;
    }
}
