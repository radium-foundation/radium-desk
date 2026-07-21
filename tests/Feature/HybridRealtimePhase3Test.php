<?php

namespace Tests\Feature;

use App\Enums\NotificationPriority;
use App\Events\Dashboard\IncomingCallReceived;
use App\Models\User;
use App\Services\HybridRealtime\HybridRealtimeNotificationBroadcaster;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HybridRealtimePhase3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_system_settings_page_shows_phase_three_hybrid_features(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.system-settings.index'))
            ->assertOk()
            ->assertSee('Incoming Calls')
            ->assertSee('Desktop Notifications')
            ->assertSee('Operator Alerts')
            ->assertSee('Notification Delivery')
            ->assertSee('Priority threshold');
    }

    public function test_notification_priority_enum_maps_alert_severity(): void
    {
        $this->assertSame('critical', NotificationPriority::Critical->value);
        $this->assertTrue(NotificationPriority::High->meetsThreshold(NotificationPriority::Normal));
        $this->assertFalse(NotificationPriority::Silent->meetsThreshold(NotificationPriority::Normal));
    }

    public function test_incoming_call_webhook_broadcasts_when_feature_enabled(): void
    {
        Event::fake([IncomingCallReceived::class]);

        config([
            'bonvoice.verify_signature' => false,
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
            'operator_alerts.enabled' => false,
        ]);

        app(SystemSettingsService::class)->set('hybrid_realtime.incoming_calls', true);

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->postJson('/api/webhooks/bonvoice', [
            'call_id' => 'call-phase3-001',
            'status' => 'Ringing',
            'direction' => 'inbound',
            'customer_phone' => '9876543210',
            'destination_number' => '1800123456',
            'event_id' => 'evt-phase3-001',
            'account_id' => 'acct-001',
        ])->assertOk();

        Event::assertDispatched(IncomingCallReceived::class);
    }

    public function test_dashboard_includes_incoming_call_card_host(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('incoming-call-card-host', false);
    }
}
