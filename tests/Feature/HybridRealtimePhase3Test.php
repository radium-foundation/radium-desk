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
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo('incidents.view');

        $response = $this->actingAs($agent)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('id="incoming-call-card-host"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'id="incoming-call-card-host"'));
    }

    public function test_incoming_call_card_host_is_available_on_incident_and_order_pages(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo('incidents.view');

        $order = \App\Models\Order::query()->create([
            'order_id' => 'RD3419607',
            'serial_number' => 'SN-HUB-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Jane Customer',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = \App\Models\Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00001',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call->value,
            'title' => 'Activation Issue',
            'description' => 'Activation failed',
            'status' => \App\Enums\IncidentStatus::Open->value,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertSee('id="incoming-call-card-host"', false);

        $this->actingAs($agent)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('id="incoming-call-card-host"', false);
    }

    public function test_non_team_member_does_not_receive_incoming_call_card_host(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);

        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="incoming-call-card-host"', false);
    }
}
