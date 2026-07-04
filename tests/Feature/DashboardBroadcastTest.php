<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\OrderTransactionService;
use App\Services\QuickServiceRequestService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $dayAdmin = User::factory()->create(['email' => 'day-admin-broadcast@test.com']);
        $dayAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    public function test_service_case_creation_triggers_dashboard_broadcast(): void
    {
        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($actor);

        app(QuickServiceRequestService::class)->create(
            user: $actor,
            orderId: 'ORD-BROADCAST-1',
            serialNumber: '7881960',
            product: 'MFS 110',
            source: \App\Enums\IncidentSource::Call,
            notes: 'Broadcast test',
        );

        $broadcastSpy->shouldHaveReceived('serviceCaseCreated')->once();
    }

    public function test_transaction_assignment_triggers_dashboard_broadcast(): void
    {
        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-TXN-1',
            'serial_number' => 'SN-TXN-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_broadcast_txn',
            'status' => 'active',
            'created_by' => $actor->id,
            'transaction_id' => null,
            'completed_at' => null,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-BROADCAST-1',
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'Test incident',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);

        $this->actingAs($actor);

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order,
            transactionId: 'TXN-12345',
            actor: $actor,
        );

        $broadcastSpy->shouldHaveReceived('transactionAssigned')->atLeast()->once();
    }
}
