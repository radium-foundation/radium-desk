<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Performance\PerformanceRuntimeConfig;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceSystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    /**
     * @param  array<string, string|int>  $overrides
     * @return array{settings: array<string, string|int>}
     */
    private function settingsPayload(array $overrides = []): array
    {
        $settings = [];

        foreach (config('system_settings.settings', []) as $key => $definition) {
            $type = $definition['type'] ?? 'string';
            $default = $definition['default'] ?? null;

            $settings[$key] = match ($type) {
                'boolean' => ($default ?? false) ? '1' : '0',
                'integer' => (string) (int) ($default ?? 0),
                'string' => (string) ($default ?? ''),
                default => (string) $default,
            };
        }

        return ['settings' => array_merge($settings, $overrides)];
    }

    public function test_performance_card_renders_with_sections(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.system-settings.index'))
            ->assertOk()
            ->assertSee('Performance')
            ->assertSee('Performance Profile')
            ->assertSee('Polling Configuration')
            ->assertSee('Hybrid Realtime')
            ->assertSee('Live Health')
            ->assertSee('Reference Number')
            ->assertSee('Balanced');
    }

    public function test_selecting_high_performance_profile_updates_polling_values(): void
    {
        $admin = $this->createAdmin();

        $payload = $this->settingsPayload([
            'performance.profile' => 'high_performance',
            'performance.polling.dashboard_live_ms' => '15000',
            'performance.polling.notification_ms' => '10000',
            'performance.polling.operations_ms' => '15000',
            'performance.polling.operations_full_refresh_ms' => '60000',
            'performance.polling.customer360_timeline_ms' => '15000',
            'performance.polling.customer360_device_sync_ms' => '5000',
            'performance.polling.presence_heartbeat_seconds' => '60',
            'performance.polling.agent_reminder_seconds' => '30',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.system-settings.update'), $payload)
            ->assertRedirect(route('admin.system-settings.index'));

        $runtime = app(PerformanceRuntimeConfig::class);

        $this->assertSame(15000, $runtime->dashboardPollIntervalMs());
        $this->assertSame(10000, $runtime->notificationPollIntervalMs());
        $this->assertSame('high_performance', app(SystemSettingsService::class)->get('performance.profile'));
    }

    public function test_polling_validation_rejects_out_of_range_values(): void
    {
        $admin = $this->createAdmin();

        $payload = $this->settingsPayload([
            'performance.profile' => 'manual',
            'performance.polling.dashboard_live_ms' => '5000',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.system-settings.update'), $payload)
            ->assertSessionHasErrors('settings.performance.polling.dashboard_live_ms');
    }

    public function test_dashboard_view_receives_runtime_poll_interval(): void
    {
        $admin = $this->createAdmin();
        app(SystemSettingsService::class)->set('realtime.polling_interval_active_seconds', 45, $admin);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-live-interval-active="45000"', false);
    }

    public function test_navbar_receives_runtime_notification_interval(): void
    {
        $admin = $this->createAdmin();
        app(SystemSettingsService::class)->set('performance.polling.notification_ms', 25000, $admin);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-poll-interval="25000"', false);
    }
}
