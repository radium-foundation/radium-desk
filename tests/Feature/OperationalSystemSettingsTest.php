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
            ->assertSee('System Settings')
            ->assertSee('WhatsApp API')
            ->assertSee('Debug mode');
    }

    public function test_seeder_sets_initial_defaults(): void
    {
        $service = app(SystemSettingsService::class);

        $this->assertFalse($service->getBool('system.debug_mode'));
        $this->assertTrue($service->getBool('notifications.whatsapp.enabled'));
        $this->assertFalse($service->getBool('notifications.email.enabled'));
        $this->assertTrue($service->getBool('whatsapp.api_enabled'));
        $this->assertTrue($service->getBool('whatsapp.manual_templates_enabled'));
        $this->assertFalse($service->getBool('whatsapp.automation_enabled'));
        $this->assertTrue($service->getBool('email.api_enabled'));
        $this->assertFalse($service->getBool('telegram.api_enabled'));
        $this->assertTrue($service->getBool('outbox.processor_enabled'));
        $this->assertTrue($service->getBool('ira.enabled'));
    }

    public function test_admin_can_update_system_settings(): void
    {
        $admin = $this->createAdmin();

        $payload = [
            'settings' => [
                'system.debug_mode' => '1',
                'notifications.whatsapp.enabled' => '1',
                'notifications.email.enabled' => '0',
                'whatsapp.api_enabled' => '0',
                'whatsapp.manual_templates_enabled' => '1',
                'whatsapp.automation_enabled' => '0',
                'email.api_enabled' => '1',
                'telegram.api_enabled' => '1',
                'outbox.processor_enabled' => '1',
                'ira.enabled' => '0',
            ],
        ];

        $this->actingAs($admin)
            ->put(route('admin.system-settings.update'), $payload)
            ->assertRedirect(route('admin.system-settings.index'))
            ->assertSessionHas('status', 'operational-system-settings-updated');

        $service = app(SystemSettingsService::class);

        $this->assertTrue($service->getBool('system.debug_mode'));
        $this->assertFalse($service->getBool('whatsapp.api_enabled'));
        $this->assertTrue($service->getBool('telegram.api_enabled'));
        $this->assertFalse($service->getBool('ira.enabled'));

        $this->assertDatabaseHas('system_settings', [
            'key' => 'system.debug_mode',
            'value' => '1',
            'updated_by' => $admin->id,
        ]);
    }

    public function test_setting_update_is_audit_logged(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->put(route('admin.system-settings.update'), [
            'settings' => [
                'system.debug_mode' => '1',
                'notifications.whatsapp.enabled' => '1',
                'notifications.email.enabled' => '0',
                'whatsapp.api_enabled' => '1',
                'whatsapp.manual_templates_enabled' => '1',
                'whatsapp.automation_enabled' => '0',
                'email.api_enabled' => '1',
                'telegram.api_enabled' => '0',
                'outbox.processor_enabled' => '1',
                'ira.enabled' => '1',
            ],
        ]);

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
