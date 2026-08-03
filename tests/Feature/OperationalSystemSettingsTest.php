<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OperationalSystemSettingsTest extends TestCase
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

    private function createAgent(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

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

    public function test_agent_cannot_access_system_settings_page(): void
    {
        $agent = $this->createAgent();

        $this->actingAs($agent)
            ->get(route('admin.system-settings.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_system_settings_page(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.system-settings.index'))
            ->assertOk()
            ->assertSee('Operational Settings')
            ->assertSee('Operational Center')
            ->assertSee('Performance')
            ->assertSee('WhatsApp notifications')
            ->assertSee('Email notifications')
            ->assertSee('Desktop notifications')
            ->assertSee('Telegram notifications')
            ->assertDontSee('Configuration Overview')
            ->assertDontSee('Cashfree')
            ->assertDontSee('Debug mode')
            ->assertDontSee('Hybrid Realtime');
    }

    public function test_overview_no_longer_duplicates_platform_runtime_monitoring_widgets(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $html = $this->actingAs($superadmin)
            ->get(route('admin.platform-configuration.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="section-overview".*?Configuration Overview/s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="section-overview".*?label">Queue</s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="section-overview".*?label">Workers</s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="section-overview".*?label">CPU Load</s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="section-overview".*?label">Memory</s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="section-overview".*?label">Response Time</s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="category-system".*?label">CPU Load</s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="category-system".*?label">Queue</s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="category-system".*?label">Failed Jobs</s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="realtime-settings-card".*?Polling active</s',
            $html,
        );
    }

    public function test_seeder_sets_initial_defaults(): void
    {
        $service = app(SystemSettingsService::class);

        $this->assertFalse($service->getBool('system.debug_mode'));
        $this->assertTrue($service->getBool('notifications.whatsapp.enabled'));
        $this->assertFalse($service->getBool('notifications.email.enabled'));
        $this->assertFalse($service->getBool('notifications.desktop.enabled'));
        $this->assertFalse($service->getBool('notifications.telegram.enabled'));
        $this->assertTrue($service->getBool('whatsapp.api_enabled'));
        $this->assertTrue($service->getBool('whatsapp.manual_templates_enabled'));
        $this->assertFalse($service->getBool('whatsapp.automation_enabled'));
        $this->assertTrue($service->getBool('email.api_enabled'));
        $this->assertFalse($service->getBool('telegram.api_enabled'));
        $this->assertTrue($service->getBool('outbox.processor_enabled'));
        $this->assertTrue($service->getBool('ira.enabled'));
        $this->assertFalse($service->getBool('automation.scheduler.enabled'));
        $this->assertSame('balanced', $service->get('performance.profile'));
        $this->assertSame(30000, $service->get('performance.polling.dashboard_live_ms'));
        $this->assertFalse($service->getBool('hybrid_realtime.reference_number'));
        $this->assertFalse($service->getBool('hybrid_realtime.assignment'));
        $this->assertFalse($service->getBool('hybrid_realtime.close_resolve'));
        $this->assertFalse($service->getBool('hybrid_realtime.incoming_calls'));
        $this->assertFalse($service->getBool('hybrid_realtime.desktop_notifications'));
        $this->assertFalse($service->getBool('hybrid_realtime.operator_alerts'));
    }

    public function test_admin_can_update_system_settings(): void
    {
        $admin = $this->createAdmin();

        $payload = $this->settingsPayload([
            'system.debug_mode' => '1',
            'notifications.email.enabled' => '0',
            'whatsapp.api_enabled' => '0',
            'telegram.api_enabled' => '1',
            'ira.enabled' => '0',
            'hybrid_realtime.reference_number' => '1',
            'hybrid_realtime.assignment' => '1',
            'hybrid_realtime.close_resolve' => '1',
            'hybrid_realtime.incoming_calls' => '1',
            'hybrid_realtime.desktop_notifications' => '1',
            'hybrid_realtime.operator_alerts' => '1',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.system-settings.update'), $payload)
            ->assertRedirect(route('admin.system-settings.index'))
            ->assertSessionHas('status', 'operational-system-settings-updated');

        $service = app(SystemSettingsService::class);

        $this->assertTrue($service->getBool('system.debug_mode'));
        $this->assertFalse($service->getBool('whatsapp.api_enabled'));
        $this->assertTrue($service->getBool('telegram.api_enabled'));
        $this->assertFalse($service->getBool('ira.enabled'));
        $this->assertTrue($service->getBool('hybrid_realtime.reference_number'));
        $this->assertTrue($service->getBool('hybrid_realtime.assignment'));
        $this->assertTrue($service->getBool('hybrid_realtime.close_resolve'));

        $this->assertDatabaseHas('system_settings', [
            'key' => 'system.debug_mode',
            'value' => '1',
            'updated_by' => $admin->id,
        ]);
    }

    public function test_setting_update_is_audit_logged(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->put(route('admin.system-settings.update'), $this->settingsPayload([
            'system.debug_mode' => '1',
        ]));

        $setting = SystemSetting::query()->where('key', 'system.debug_mode')->first();

        $this->assertNotNull($setting);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'system_setting.updated',
            'auditable_type' => $setting->getMorphClass(),
            'auditable_id' => $setting->id,
        ]);
    }

    public function test_setting_update_invalidates_only_relevant_cache_key(): void
    {
        $service = app(SystemSettingsService::class);

        $this->assertFalse($service->getBool('system.debug_mode'));
        $this->assertTrue($service->getBool('whatsapp.api_enabled'));

        Cache::put('system_settings.key.system.debug_mode', 'stale', now()->addHour());
        Cache::put('system_settings.key.whatsapp.api_enabled', '1', now()->addHour());

        $admin = $this->createAdmin();

        $service->set('system.debug_mode', true, $admin);

        $this->assertFalse(Cache::has('system_settings.key.system.debug_mode'));
        $this->assertTrue(Cache::has('system_settings.key.whatsapp.api_enabled'));
        $this->assertTrue($service->getBool('system.debug_mode'));
    }
}
