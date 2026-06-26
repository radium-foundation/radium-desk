<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardChannelAuthorization;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dashboard_channel_allows_owner_with_incidents_view(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertTrue(DashboardChannelAuthorization::canSubscribeToDashboard($user, $user->id));
    }

    public function test_dashboard_channel_denies_other_users(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $other = User::factory()->create();
        $other->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertFalse(DashboardChannelAuthorization::canSubscribeToDashboard($user, $other->id));
    }

    public function test_dashboard_channel_denies_users_without_incidents_view(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(DashboardChannelAuthorization::canSubscribeToDashboard($user, $user->id));
    }

    public function test_incident_channel_allows_authorized_viewers(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incident = Incident::query()->create([
            'order_id' => Order::query()->create([
                'order_id' => 'ORD-AUTH-1',
                'serial_number' => 'SN-AUTH-1',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $user->id,
            ])->id,
            'reference_no' => 'SC-AUTH-1',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'Test incident',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $this->assertTrue(DashboardChannelAuthorization::canSubscribeToIncident($user, $incident->id));
    }

    public function test_notifications_channel_allows_only_owner(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue(DashboardChannelAuthorization::canSubscribeToNotifications($user, $user->id));
        $this->assertFalse(DashboardChannelAuthorization::canSubscribeToNotifications($user, $other->id));
    }

    public function test_broadcasting_auth_denies_foreign_dashboard_channel_over_http(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options' => [
                'host' => 'localhost',
                'port' => 8080,
                'scheme' => 'http',
            ],
        ]);

        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $other = User::factory()->create();
        $other->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)->post('/broadcasting/auth', [
            'channel_name' => 'private-dashboard.'.$other->id,
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }
}
