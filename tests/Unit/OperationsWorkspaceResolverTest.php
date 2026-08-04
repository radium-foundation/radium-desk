<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Dashboard\OperationsWorkspaceResolver;
use App\Services\DashboardPersonalizationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OperationsWorkspaceResolverTest extends TestCase
{
    use RefreshDatabase;

    private OperationsWorkspaceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->resolver = app(OperationsWorkspaceResolver::class);
    }

    public function test_workspace_query_normalizes_to_queue_without_changing_membership(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $fromWorkspace = $this->resolver->resolve($admin, Request::create('/dashboard', 'GET', [
            'workspace' => 'attention',
        ]));
        $fromQueue = $this->resolver->resolve($admin, Request::create('/dashboard', 'GET', [
            'queue' => 'attention',
        ]));

        $this->assertSame('attention', $fromWorkspace['workspace']);
        $this->assertSame('attention', $fromWorkspace['operation_queue']);
        $this->assertSame('attention', $fromWorkspace['service_case_filter']);
        $this->assertSame($fromQueue['operation_queue'], $fromWorkspace['operation_queue']);
        $this->assertSame($fromQueue['service_case_filter'], $fromWorkspace['service_case_filter']);
        $this->assertSame($fromQueue['panel_title'], $fromWorkspace['panel_title']);
    }

    public function test_legacy_filter_overdue_still_resolves_for_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $fromFilter = $this->resolver->resolve($admin, Request::create('/dashboard', 'GET', [
            'filter' => 'overdue',
        ]));
        $fromWorkspace = $this->resolver->resolve($admin, Request::create('/dashboard', 'GET', [
            'workspace' => 'overdue',
        ]));

        $this->assertSame(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $fromFilter['operation_queue']);
        $this->assertSame('overdue', $fromFilter['service_case_filter']);
        $this->assertSame('overdue', $fromFilter['workspace']);
        $this->assertSame($fromFilter['operation_queue'], $fromWorkspace['operation_queue']);
        $this->assertSame($fromFilter['service_case_filter'], $fromWorkspace['service_case_filter']);
    }

    public function test_agent_overdue_workspace_maps_to_my_work_queue(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $resolved = $this->resolver->resolve($agent, Request::create('/dashboard', 'GET', [
            'workspace' => 'overdue',
        ]));

        $this->assertSame(DashboardPersonalizationService::QUEUE_MY_WORK, $resolved['operation_queue']);
        $this->assertSame('overdue', $resolved['service_case_filter']);
        $this->assertSame('overdue', $resolved['workspace']);
    }

    public function test_soft_switch_flag_defaults_enabled(): void
    {
        $this->assertTrue($this->resolver->softSwitchEnabled());
    }
}
