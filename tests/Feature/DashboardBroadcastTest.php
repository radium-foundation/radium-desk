<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\OrderStatus;
use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Events\Dashboard\SlaStatusChanged;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\DashboardService;
use App\Services\OrderTransactionService;
use App\Services\QuickServiceRequestService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

        $order = Order::query()->create([
            'order_id' => 'INQ-BROADCAST-1',
            'serial_number' => '7881960',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
        ]);

        app(QuickServiceRequestService::class)->createForOrder(
            user: $actor,
            order: $order,
            source: IncidentSource::Call,
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
            'source' => IncidentSource::Call,
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

    public function test_kpi_strip_renders_with_authenticated_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin);

        $html = app(DashboardService::class)->renderKpiStrip(
            app(DashboardService::class)->statsFor($admin),
        );

        $this->assertStringContainsString('dashboard-kpi-strip', $html);
        $this->assertStringContainsString('Open', $html);
    }

    public function test_kpi_strip_renders_from_queue_context_using_recipient_viewer(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        auth()->logout();

        $html = app(DashboardService::class)->renderKpiStrip(
            app(DashboardService::class)->statsFor($admin),
            $admin,
        );

        $this->assertStringContainsString('dashboard-kpi-strip', $html);
        $this->assertStringContainsString('Open', $html);
        $this->assertStringNotContainsString('My Active Work', $html);
    }

    public function test_dashboard_broadcast_does_not_throw_when_auth_user_is_null(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        auth()->logout();

        app(DashboardBroadcastService::class)->kpisUpdated(null);

        $this->assertTrue(true);
    }

    public function test_service_case_created_broadcasts_rows_without_sync_kpi_fanout(): void
    {
        Event::fake([ServiceCaseCreated::class, SlaStatusChanged::class, DashboardKpisUpdated::class]);

        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-KPI-LIGHT-1',
            'serial_number' => 'SN-KPI-LIGHT-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_kpi_light_1',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-KPI-LIGHT-1',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'KPI light path',
            'description' => 'KPI light path',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);

        app(DashboardBroadcastService::class)->serviceCaseCreated($incident->fresh(), $actor);

        Event::assertDispatched(ServiceCaseCreated::class, function (ServiceCaseCreated $event) use ($viewer, $incident): bool {
            return $event->recipient->id === $viewer->id
                && $event->incident->id === $incident->id
                && is_string($event->rowHtml)
                && $event->rowHtml !== '';
        });

        Event::assertDispatched(SlaStatusChanged::class, function (SlaStatusChanged $event) use ($viewer, $incident): bool {
            return $event->recipient->id === $viewer->id
                && $event->incident->id === $incident->id;
        });

        Event::assertNotDispatched(DashboardKpisUpdated::class);
        $this->assertTrue($incident->fresh()->isPendingAdmin());
    }

    public function test_explicit_kpis_updated_still_rebuilds_per_recipient_metrics(): void
    {
        Event::fake([DashboardKpisUpdated::class]);

        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(DashboardBroadcastService::class)->kpisUpdated($actor);

        Event::assertDispatched(DashboardKpisUpdated::class, function (DashboardKpisUpdated $event) use ($viewer): bool {
            return $event->recipient->id === $viewer->id
                && str_contains($event->kpiStripHtml, 'dashboard-kpi-strip');
        });
    }
}
