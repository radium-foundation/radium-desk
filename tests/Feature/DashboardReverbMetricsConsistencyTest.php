<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Events\Dashboard\DashboardKpisUpdated;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DashboardReverbMetricsConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $dayAdmin = User::factory()->create(['email' => 'day-admin-metrics@test.com']);
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

    public function test_no_remembered_queue_cache_exists(): void
    {
        $user = $this->createAdmin('Cache Admin');

        $this->actingAs($user)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER]))
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_SCHEDULED]))
            ->assertOk();

        $this->assertFalse(Cache::has('dashboard.live_operation_queue.'.$user->id));
        $this->assertFalse(method_exists(app(DashboardPersonalizationService::class), 'rememberLiveOperationQueue'));
        $this->assertFalse(method_exists(app(DashboardPersonalizationService::class), 'rememberedLiveOperationQueue'));
    }

    public function test_reverb_variants_match_polling_for_each_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent('Metrics Agent');
        $creator = $this->createAdmin('Metrics Admin');

        $this->createAssignedWaitingCase('RD-METRICS-A-1', $creator, $agent);
        $this->createAssignedWaitingCase('RD-METRICS-A-2', $creator, $agent);
        $this->createAssignedWaitingCase('RD-METRICS-B-1', $creator, $this->createAgent('Other Agent'));

        $supportScopeCounts = $this->actingAs($agent)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_MY_WORK]))
            ->assertOk()
            ->json('service_case_filter_counts');

        $operationsScopeCounts = $this->actingAs($agent)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_SCHEDULED]))
            ->assertOk()
            ->json('service_case_filter_counts');

        $liveKpiStrip = $this->actingAs($agent)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_MY_WORK]))
            ->assertOk()
            ->json('kpi_strip_html');

        Event::fake([DashboardKpisUpdated::class]);

        app(DashboardBroadcastService::class)->kpisUpdated($creator);

        Event::assertDispatched(DashboardKpisUpdated::class, function (DashboardKpisUpdated $event) use ($agent, $supportScopeCounts, $operationsScopeCounts, $liveKpiStrip): bool {
            return $event->recipient->id === $agent->id
                && $event->serviceCaseFilterCountVariants[DashboardPersonalizationService::SCOPE_SUPPORT] === $supportScopeCounts
                && $event->serviceCaseFilterCountVariants[DashboardPersonalizationService::SCOPE_OPERATIONS] === $operationsScopeCounts
                && $event->kpiStripHtml === $liveKpiStrip;
        });

        Carbon::setTestNow();
    }

    public function test_operations_only_users_omit_support_scope_variant(): void
    {
        $admin = $this->createAdmin('Operations Only Admin');
        $actor = $this->createAdmin('Operations Only Actor');

        Event::fake([DashboardKpisUpdated::class]);

        app(DashboardBroadcastService::class)->kpisUpdated($actor);

        Event::assertDispatched(DashboardKpisUpdated::class, function (DashboardKpisUpdated $event) use ($admin): bool {
            return $event->recipient->id === $admin->id
                && array_key_exists(DashboardPersonalizationService::SCOPE_OPERATIONS, $event->serviceCaseFilterCountVariants)
                && ! array_key_exists(DashboardPersonalizationService::SCOPE_SUPPORT, $event->serviceCaseFilterCountVariants);
        });
    }

    public function test_different_users_receive_different_operations_scope_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('Scope Admin');
        $agent = $this->createAgent('Scope Agent');
        $creator = $this->createAdmin('Scope Creator');

        $this->createAssignedWaitingCase('RD-SCOPE-A', $creator, $agent);
        $this->createAssignedWaitingCase('RD-SCOPE-B', $creator, $agent);
        $this->createAssignedWaitingCase('RD-SCOPE-C', $creator, null);

        $adminLiveCounts = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER]))
            ->assertOk()
            ->json('service_case_filter_counts');

        $agentLiveCounts = $this->actingAs($agent)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER]))
            ->assertOk()
            ->json('service_case_filter_counts');

        $this->assertSame(3, $adminLiveCounts['waiting_customer']);
        $this->assertSame(2, $agentLiveCounts['waiting_customer']);

        Event::fake([DashboardKpisUpdated::class]);
        app(DashboardBroadcastService::class)->kpisUpdated($creator);

        Event::assertDispatched(DashboardKpisUpdated::class, function (DashboardKpisUpdated $event) use ($admin, $adminLiveCounts): bool {
            return $event->recipient->id === $admin->id
                && $event->serviceCaseFilterCountVariants[DashboardPersonalizationService::SCOPE_OPERATIONS] === $adminLiveCounts;
        });

        Event::assertDispatched(DashboardKpisUpdated::class, function (DashboardKpisUpdated $event) use ($agent, $agentLiveCounts): bool {
            return $event->recipient->id === $agent->id
                && $event->serviceCaseFilterCountVariants[DashboardPersonalizationService::SCOPE_SUPPORT] === $agentLiveCounts;
        });

        Carbon::setTestNow();
    }

    public function test_scope_for_queue_maps_support_and_operations_queues(): void
    {
        $personalization = app(DashboardPersonalizationService::class);
        $agent = $this->createAgent('Scope Mapping Agent');
        $admin = $this->createAdmin('Scope Mapping Admin');

        $this->assertSame(
            DashboardPersonalizationService::SCOPE_SUPPORT,
            $personalization->scopeForQueue(DashboardPersonalizationService::QUEUE_MY_WORK, $agent),
        );
        $this->assertSame(
            DashboardPersonalizationService::SCOPE_SUPPORT,
            $personalization->scopeForQueue(DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER, $agent),
        );
        $this->assertSame(
            DashboardPersonalizationService::SCOPE_OPERATIONS,
            $personalization->scopeForQueue(DashboardPersonalizationService::QUEUE_SCHEDULED, $agent),
        );
        $this->assertSame(
            DashboardPersonalizationService::SCOPE_OPERATIONS,
            $personalization->scopeForQueue('business_hold', $agent),
        );
        $this->assertSame(
            DashboardPersonalizationService::SCOPE_OPERATIONS,
            $personalization->scopeForQueue(DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER, $admin),
        );
    }

    public function test_dashboard_renders_live_scope_attribute_for_active_queue(): void
    {
        $agent = $this->createAgent('Blade Scope Agent');

        $this->actingAs($agent)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_MY_WORK]))
            ->assertOk()
            ->assertSee('data-live-scope="support_scope"', false);

        $this->actingAs($agent)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_SCHEDULED]))
            ->assertOk()
            ->assertSee('data-live-scope="operations_scope"', false);
    }

    public function test_live_reverb_metrics_for_matches_expected_variants(): void
    {
        $agent = $this->createAgent('Reverb Metrics Agent');

        app(DashboardService::class)->forgetSnapshot();

        $metrics = app(DashboardService::class)->liveReverbMetricsFor($agent);
        $dashboardService = app(DashboardService::class);

        $this->assertSame(
            $dashboardService->serviceCaseFilterCounts(null, $agent),
            $metrics['service_case_filter_count_variants'][DashboardPersonalizationService::SCOPE_OPERATIONS],
        );
        $this->assertSame(
            $dashboardService->serviceCaseFilterCounts($agent, $agent),
            $metrics['service_case_filter_count_variants'][DashboardPersonalizationService::SCOPE_SUPPORT],
        );
    }

    private function createAdmin(string $name): User
    {
        $admin = User::factory()->create(['name' => $name, 'is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createAgent(string $name): User
    {
        $agent = User::factory()->create(['name' => $name, 'is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    private function createAssignedWaitingCase(string $orderId, User $creator, ?User $assignee): Incident
    {
        $incident = $this->createIncident($orderId, $creator, $assignee);

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        return $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);
    }

    private function createIncident(string $orderId, User $creator, ?User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-'.$orderId,
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => "Case {$orderId}",
            'description' => "Case {$orderId}.",
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $creator->id,
        ]);
    }
}
