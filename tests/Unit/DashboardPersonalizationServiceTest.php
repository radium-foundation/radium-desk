<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\DashboardPersonalizationService;
use App\Services\Operations\OperationsRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPersonalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardPersonalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(DashboardPersonalizationService::class);
    }

    public function test_default_queues_follow_role_personalization(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->assertSame(DashboardPersonalizationService::QUEUE_MY_WORK, $this->service->defaultQueueFor($agent));
        $this->assertSame(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $this->service->defaultQueueFor($admin));
        $this->assertSame(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $this->service->defaultQueueFor($superadmin));
    }

    public function test_hardware_aliases_normalize_to_hardware_queue(): void
    {
        $this->assertSame(
            DashboardPersonalizationService::QUEUE_HARDWARE,
            $this->service->normalizeRequestedQueue('hardware'),
        );
        $this->assertSame(
            DashboardPersonalizationService::QUEUE_HARDWARE,
            $this->service->normalizeRequestedQueue('warehouse'),
        );
    }

    public function test_agent_hardware_request_requires_redirect(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $resolution = $this->service->resolveQueue($agent, 'hardware');

        $this->assertTrue($resolution['redirect']);
        $this->assertSame(DashboardPersonalizationService::QUEUE_MY_WORK, $resolution['queue']);
    }

    public function test_support_user_queue_navigation_includes_my_work_and_waiting_customer(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $queues = $this->service->availableQueuesFor($agent);

        $this->assertSame([
            DashboardPersonalizationService::QUEUE_MY_WORK,
            DashboardPersonalizationService::QUEUE_SCHEDULED,
            DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER,
            DashboardPersonalizationService::QUEUE_COMPLETED,
        ], $queues);
    }

    public function test_admin_queue_navigation_includes_hardware_when_permitted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $queues = $this->service->availableQueuesFor($admin);

        $this->assertContains(DashboardPersonalizationService::QUEUE_HARDWARE, $queues);
        $this->assertContains(DashboardPersonalizationService::QUEUE_ATTENTION, $queues);
    }

    public function test_legacy_view_and_filter_map_to_operation_queue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $mapped = $this->service->resolveQueue($admin, null, 'hardware_orders', null);
        $this->assertSame(DashboardPersonalizationService::QUEUE_HARDWARE, $mapped['queue']);

        $attention = $this->service->resolveQueue($admin, null, 'all', 'needs_attention');
        $this->assertSame(DashboardPersonalizationService::QUEUE_ATTENTION, $attention['queue']);
    }

    public function test_module_navigation_is_disabled_in_favor_of_queue_navigation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertFalse($this->service->showsModuleNavigation($admin));
        $this->assertTrue($this->service->showsQueueNavigation($admin));
    }
}
