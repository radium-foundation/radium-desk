<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardService;
use App\Services\Operations\OperationsQueueClassifier;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DashboardLiveMetricCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_active_cases_count_endpoint_returns_correct_count(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createIncidentWithStatus(IncidentStatus::Open);
        $this->createIncidentWithStatus(IncidentStatus::InProgress);
        $this->createIncidentWithStatus(IncidentStatus::AwaitingProductDetails);
        $this->createIncidentWithStatus(IncidentStatus::Resolved);
        $this->createIncidentWithStatus(IncidentStatus::Closed);

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.counts', ['metric' => 'active_cases']))
            ->assertOk()
            ->assertExactJson([
                'metric' => 'active_cases',
                'count' => 3,
            ]);
    }

    public function test_active_cases_count_requires_incidents_view_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $this->actingAs($user)
            ->getJson(route('dashboard.live.counts', ['metric' => 'active_cases']))
            ->assertForbidden();
    }

    public function test_active_cases_count_requires_admin_queue_role(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo('incidents.view');

        $this->actingAs($agent)
            ->getJson(route('dashboard.live.counts', ['metric' => 'active_cases']))
            ->assertForbidden();
    }

    public function test_active_cases_count_rejects_unknown_metric(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.counts', ['metric' => 'open_cases']))
            ->assertNotFound();
    }

    public function test_pending_refunds_count_endpoint_returns_correct_count(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('refunds.view');

        $order = Order::query()->create([
            'order_id' => 'ORD-REFUND-METRIC',
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-METRIC-1',
            'amount' => 100,
            'reason' => 'Pending refund metric test.',
            'status' => RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-METRIC-2',
            'amount' => 100,
            'reason' => 'Second pending refund metric test.',
            'status' => RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-METRIC-3',
            'amount' => 100,
            'reason' => 'Completed refund metric test.',
            'status' => RefundStatus::Completed,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.counts', ['metric' => 'pending_refunds']))
            ->assertOk()
            ->assertJsonPath('metric', 'pending_refunds')
            ->assertJsonPath('count', 2);
    }

    public function test_pending_refunds_count_requires_refunds_view_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $this->actingAs($user)
            ->getJson(route('dashboard.live.counts', ['metric' => 'pending_refunds']))
            ->assertForbidden();
    }

    public function test_pending_refunds_count_does_not_call_service_case_filter_counts(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('refunds.view');

        $real = $this->app->make(DashboardService::class);
        $spy = Mockery::mock($real)->makePartial();
        $spy->shouldReceive('serviceCaseFilterCounts')->never();
        $this->app->instance(DashboardService::class, $spy);

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.counts', ['metric' => 'pending_refunds']))
            ->assertOk();
    }

    public function test_active_cases_count_does_not_call_service_case_filter_counts(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $real = $this->app->make(DashboardService::class);
        $spy = Mockery::mock($real)->makePartial();
        $spy->shouldReceive('serviceCaseFilterCounts')->never();
        $this->app->instance(DashboardService::class, $spy);

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.counts', ['metric' => 'active_cases']))
            ->assertOk();
    }

    public function test_active_cases_count_does_not_classify_incidents(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $classifier = Mockery::mock(OperationsQueueClassifier::class);
        $classifier->shouldReceive('classify')->never();
        $classifier->shouldReceive('rememberClassifications')->never();
        $classifier->shouldReceive('forgetClassifications')->andReturnNull();
        $this->app->instance(OperationsQueueClassifier::class, $classifier);

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.counts', ['metric' => 'active_cases']))
            ->assertOk();
    }

    public function test_active_cases_count_does_not_load_dashboard_snapshot(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $store = Mockery::mock(DashboardSnapshotStore::class);
        $store->shouldReceive('get')->never();
        $store->shouldReceive('forget')->andReturnNull();
        $this->app->instance(DashboardSnapshotStore::class, $store);

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.counts', ['metric' => 'active_cases']))
            ->assertOk();
    }

    public function test_active_cases_count_uses_single_aggregate_query(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($i = 0; $i < 12; $i++) {
            $this->createIncidentWithStatus(IncidentStatus::Open, "ORD-METRIC-{$i}");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $started = hrtime(true);
        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.live.counts', ['metric' => 'active_cases']))
            ->assertOk()
            ->assertJsonPath('count', 12);
        $wallMs = (hrtime(true) - $started) / 1e6;

        $queries = DB::getQueryLog();
        $incidentCountQueries = collect($queries)->filter(function (array $query): bool {
            $sql = strtolower($query['query'] ?? '');

            return str_contains($sql, 'incidents')
                && str_contains($sql, 'count(');
        });

        fwrite(STDERR, sprintf(
            "\nBENCH active_cases_count: wall_ms=%.2f sql=%d incident_count_sql=%d bytes=%d\n",
            $wallMs,
            count($queries),
            $incidentCountQueries->count(),
            strlen((string) $response->getContent()),
        ));

        $this->assertSame(1, $incidentCountQueries->count());
        $this->assertLessThan(200, strlen((string) $response->getContent()));
        $this->assertLessThan(1000, $wallMs);
    }

    public function test_active_cases_workspace_list_remains_paginated(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($i = 0; $i < 20; $i++) {
            $this->createIncidentWithStatus(IncidentStatus::Open, "ORD-LIST-{$i}");
        }

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.workspace', [
                'workspace' => 'active_cases',
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertJsonPath('workspace', 'active_cases');

        $html = (string) $response->json('panel_html');
        $this->assertStringContainsString('data-operations-embedded-panel="active_cases"', $html);
        $this->assertStringContainsString('pagination', strtolower($html));
    }

    public function test_ready_queue_live_refresh_still_returns_rows(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-READY-METRIC',
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'RD-METRIC-READY',
            'category' => 'General',
            'source' => 'cashfree',
            'title' => 'Ready metric regression',
            'description' => 'Ready queue regression case.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'assigned_to_user_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => 'action_required']))
            ->assertOk()
            ->assertJsonStructure(['rows', 'service_case_filter_counts']);
    }

    private function createIncidentWithStatus(IncidentStatus $status, ?string $orderId = null): Incident
    {
        $admin = User::query()->first() ?? User::factory()->create(['is_active' => true]);

        $order = Order::query()->create([
            'order_id' => $orderId ?? 'ORD-'.uniqid(),
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'RD-'.uniqid(),
            'category' => 'General',
            'source' => 'cashfree',
            'title' => "Case {$order->order_id}",
            'description' => 'Metric count fixture.',
            'status' => $status,
            'assigned_to_user_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }
}
