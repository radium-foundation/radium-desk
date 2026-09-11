<?php

namespace Tests\Feature;

use App\Enums\Assignment\AssignmentCapability;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsRoleService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SettingService;
use App\Support\Assignment\Capabilities\UserCapabilityService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReadyQueueCapabilityAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_admin_role_receives_ready_queue_permission_by_default(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertTrue($admin->can(RolePermissionSeeder::PERMISSION_READY_QUEUE_VIEW));
        $this->assertTrue(app(OperationsRoleService::class)->canViewReadyQueue($admin));
    }

    public function test_hardware_team_without_capability_cannot_view_ready_queue(): void
    {
        $operator = User::factory()->create(['email' => 'warehouse-operator@example.com']);
        $operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->assertFalse($operator->can(RolePermissionSeeder::PERMISSION_READY_QUEUE_VIEW));
        $this->assertFalse(app(OperationsRoleService::class)->canViewReadyQueue($operator));

        $queues = app(DashboardPersonalizationService::class)->availableQueuesFor($operator);

        $this->assertNotContains(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $queues);
        $this->assertContains(DashboardPersonalizationService::QUEUE_HARDWARE, $queues);

        $response = $this->actingAs($operator)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]));

        $response->assertRedirect(route('dashboard'));
        $this->assertSame(
            DashboardPersonalizationService::QUEUE_HARDWARE,
            app(DashboardPersonalizationService::class)->defaultQueueFor($operator),
        );
    }

    public function test_hardware_team_ready_queue_admin_capability_grants_ready_queue_access(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00', 'Asia/Kolkata'));

        $operator = User::factory()->create(['email' => 'hybrid-operator@example.com']);
        $operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.ready_queue_day_admin_user_id' => (string) $operator->id,
            'assignment.ready_queue_night_admin_user_id' => (string) $operator->id,
        ]);

        $this->assertTrue(
            app(UserCapabilityService::class)->userHasCapability(
                $operator,
                AssignmentCapability::ReadyQueueAdmin,
            ),
        );
        $this->assertTrue(app(OperationsRoleService::class)->canViewReadyQueue($operator));

        $personalization = app(DashboardPersonalizationService::class);
        $queues = $personalization->availableQueuesFor($operator);

        $this->assertSame(
            DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
            $personalization->defaultQueueFor($operator),
        );
        $this->assertSame(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $queues[0]);
        $this->assertContains(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $queues);
        $this->assertContains(DashboardPersonalizationService::QUEUE_HARDWARE, $queues);
        $this->assertNotContains(DashboardPersonalizationService::QUEUE_ATTENTION, $queues);

        Carbon::setTestNow();
    }

    public function test_admin_with_hardware_team_lands_on_ready_queue_dashboard(): void
    {
        $operator = User::factory()->create(['email' => 'hybrid-admin@example.com']);
        $operator->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ready Queue')
            ->assertSee('data-operations-widget="ready-queue"', false)
            ->assertDontSee('data-operations-widget="hardware-workspace"', false);

        $resolution = app(DashboardPersonalizationService::class)->resolveQueue($operator, 'action_required');

        $this->assertFalse($resolution['redirect']);
        $this->assertSame(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $resolution['queue']);
    }

    public function test_ready_queue_admin_capability_can_retrieve_and_work_rd_service_tasks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00', 'Asia/Kolkata'));

        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $operator = User::factory()->create(['email' => 'hybrid-operator@example.com']);
        $operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        app(SettingService::class)->set('assignment.ready_queue_day_admin_user_id', (string) $operator->id);

        $incident = $this->createAwaitingProductDetailsCase('RD3519999', $creator, $operator);

        $this->actingAs($operator)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]))
            ->assertOk()
            ->assertSee('Ready Queue')
            ->assertSee('RD3519999');

        $this->actingAs($operator)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]))
            ->assertOk()
            ->assertJsonCount(1, 'rows');

        $this->actingAs($operator)
            ->get(route('incidents.show', $incident))
            ->assertOk();

        $this->actingAs($operator)
            ->from(route('incidents.show', $incident))
            ->post(route('remarks.store'), [
                'remarkable_type' => Incident::class,
                'remarkable_id' => $incident->id,
                'body' => 'RD service follow-up completed.',
            ])
            ->assertRedirect(route('incidents.show', $incident).'#activity-timeline');

        $this->assertDatabaseHas('remarks', [
            'remarkable_id' => $incident->id,
            'body' => 'RD service follow-up completed.',
        ]);

        Carbon::setTestNow();
    }

    public function test_ready_queue_access_survives_permission_cache_refresh(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00', 'Asia/Kolkata'));

        $operator = User::factory()->create();
        $operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        app(SettingService::class)->set('assignment.ready_queue_day_admin_user_id', (string) $operator->id);

        $roles = app(OperationsRoleService::class);
        $this->assertTrue($roles->canViewReadyQueue($operator));

        $operator->refresh();
        $operator->load('roles', 'permissions');

        $this->assertTrue($roles->canViewReadyQueue($operator));

        Carbon::setTestNow();
    }

    public function test_no_user_specific_ready_queue_bypass_exists_in_operations_role_service(): void
    {
        $source = file_get_contents(app_path('Services/Operations/OperationsRoleService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('avinash', strtolower($source));
        $this->assertStringNotContainsString('user_id', strtolower($source));
    }

    private function createAwaitingProductDetailsCase(string $orderId, User $creator, User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Case {$orderId}",
            'description' => "Awaiting product details for {$orderId}.",
            'status' => IncidentStatus::AwaitingProductDetails,
            'assigned_to_user_id' => $assignee->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
