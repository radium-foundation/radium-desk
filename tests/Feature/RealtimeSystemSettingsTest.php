<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Realtime\RealtimeConnectionStatusService;
use App\Services\Realtime\RealtimeRuntimeConfig;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeSystemSettingsTest extends TestCase
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

    public function test_realtime_card_renders_with_connection_status(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.system-settings.index'))
            ->assertOk()
            ->assertSee('Realtime')
            ->assertSee('Connection Status')
            ->assertSee('Realtime provider');
    }

    public function test_provider_polling_forces_dashboard_poll_mode(): void
    {
        config([
            'broadcasting.default' => 'ably',
            'broadcasting.connections.ably.key' => 'public:secret',
        ]);

        app(SystemSettingsService::class)->set('realtime.provider', 'polling');

        $runtime = app(RealtimeRuntimeConfig::class);

        $this->assertSame('polling', $runtime->provider());
        $this->assertSame('poll', $runtime->dashboardTransportMode());
        $this->assertFalse($runtime->echoConfigured());
    }

    public function test_realtime_disabled_forces_polling_without_echo(): void
    {
        config([
            'broadcasting.default' => 'ably',
            'broadcasting.connections.ably.key' => 'public:secret',
        ]);

        app(SystemSettingsService::class)->set('realtime.enabled', false);

        $runtime = app(RealtimeRuntimeConfig::class);

        $this->assertSame('poll', $runtime->dashboardTransportMode());
        $this->assertFalse($runtime->echoConfigured());
    }

    public function test_auto_provider_follows_broadcast_driver(): void
    {
        config([
            'broadcasting.default' => 'ably',
            'broadcasting.connections.ably.key' => 'public:secret',
        ]);

        $runtime = app(RealtimeRuntimeConfig::class);

        $this->assertSame('ably', $runtime->provider());
        $this->assertTrue($runtime->echoConfigured());
    }

    public function test_dashboard_reports_connection_status(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)->postJson(route('dashboard.realtime.connection-status'), [
            'status' => 'connected',
            'provider' => 'ably',
        ])->assertOk();

        $snapshot = app(RealtimeConnectionStatusService::class)->snapshot();

        $this->assertSame('connected', $snapshot['status']);
        $this->assertNotNull($snapshot['last_connected_at']);
        $this->assertSame($user->id, $snapshot['reported_by_user_id']);
    }

    public function test_non_superadmin_cannot_enable_debug_mode(): void
    {
        $admin = $this->createAdmin();

        $payload = $this->settingsPayload([
            'realtime.debug_mode' => '1',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.system-settings.index'))
            ->put(route('admin.system-settings.update'), $payload)
            ->assertSessionHasErrors('settings.realtime.debug_mode');

        $this->assertFalse(app(SystemSettingsService::class)->getBool('realtime.debug_mode'));
    }

    public function test_admin_can_reset_connection_status(): void
    {
        $admin = $this->createAdmin();

        app(RealtimeConnectionStatusService::class)->recordConnected($admin, 'ably');

        $this->actingAs($admin)
            ->post(route('admin.system-settings.realtime.reset-status'))
            ->assertRedirect(route('admin.system-settings.index'));

        $this->assertSame('unknown', app(RealtimeConnectionStatusService::class)->snapshot()['status']);
    }

    public function test_admin_test_endpoint_reports_missing_ably_key(): void
    {
        config([
            'broadcasting.default' => 'ably',
            'broadcasting.connections.ably.key' => '',
        ]);

        app(SystemSettingsService::class)->set('realtime.provider', 'ably');

        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->postJson(route('admin.system-settings.realtime.test'))
            ->assertStatus(422)
            ->assertJsonPath('provider', 'ably');
    }

    public function test_dashboard_includes_realtime_data_attributes(): void
    {
        config([
            'broadcasting.default' => 'ably',
            'broadcasting.connections.ably.key' => 'public:secret',
        ]);

        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-live-interval-active', false)
            ->assertSee('data-realtime-provider', false)
            ->assertSee('data-echo-broadcaster="ably"', false);
    }
}
