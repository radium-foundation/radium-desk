<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\DashboardPersonalizationService;
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

    public function test_system_context_uses_safe_default_view_and_queue(): void
    {
        $this->assertSame(
            DashboardPersonalizationService::VIEW_ALL,
            $this->service->defaultViewFor(null),
        );
        $this->assertSame(
            DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
            $this->service->defaultQueueFor(null),
        );
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
        ], $queues);
        $this->assertNotContains(DashboardPersonalizationService::QUEUE_COMPLETED, $queues);
        $this->assertNotContains(DashboardPersonalizationService::QUEUE_PENDING_REVIEW, $queues);
    }

    public function test_support_agent_completed_queue_scopes_to_assignee(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertSame(
            $agent->id,
            $this->service->resolveAssignedToScope($agent, DashboardPersonalizationService::QUEUE_COMPLETED)?->id,
        );
        $this->assertNull(
            $this->service->resolveAssignedToScope($admin, DashboardPersonalizationService::QUEUE_COMPLETED),
        );
    }

    public function test_admin_queue_navigation_includes_hardware_when_permitted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $queues = $this->service->availableQueuesFor($admin);

        $this->assertContains(DashboardPersonalizationService::QUEUE_HARDWARE, $queues);
        $this->assertContains(DashboardPersonalizationService::QUEUE_ATTENTION, $queues);
        $this->assertNotContains(DashboardPersonalizationService::QUEUE_COMPLETED, $queues);
        $this->assertNotContains(DashboardPersonalizationService::QUEUE_PENDING_REVIEW, $queues);
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

    public function test_legacy_completed_filter_redirects_to_default_queue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $resolution = $this->service->resolveQueue($admin, null, null, 'completed');

        $this->assertTrue($resolution['redirect']);
        $this->assertSame(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $resolution['queue']);
        $this->assertSame(
            'action_required',
            $this->service->resolveServiceCaseFilter($admin, null, null, 'completed'),
        );
    }

    public function test_legacy_pending_review_urls_redirect_to_default_queue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $adminQueueResolution = $this->service->resolveQueue($admin, null, null, 'pending_review');
        $this->assertTrue($adminQueueResolution['redirect']);
        $this->assertSame(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $adminQueueResolution['queue']);

        $agentQueueResolution = $this->service->resolveQueue($agent, 'pending_review');
        $this->assertTrue($agentQueueResolution['redirect']);
        $this->assertSame(DashboardPersonalizationService::QUEUE_MY_WORK, $agentQueueResolution['queue']);

        $this->assertSame(
            'action_required',
            $this->service->resolveServiceCaseFilter($admin, null, null, 'pending_review'),
        );
        $this->assertSame(
            'my_cases',
            $this->service->resolveServiceCaseFilter($agent, null, null, 'pending_review'),
        );
    }

    public function test_module_navigation_is_disabled_in_favor_of_queue_navigation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertFalse($this->service->showsModuleNavigation($admin));
        $this->assertTrue($this->service->showsQueueNavigation($admin));
    }
}
