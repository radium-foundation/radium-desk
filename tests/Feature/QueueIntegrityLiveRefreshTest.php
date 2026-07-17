<?php

namespace Tests\Feature;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardBroadcastService;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QueueIntegrityLiveRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_manual_assignment_marks_case_for_ready_queue_removal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('admin@example.com');
        $agent = $this->createAgentUser('agent@example.com');
        $this->configureAssignmentSettings($admin->id);

        $order = $this->createValidatedOrder($admin, 'RD-QUEUE-LIVE-1');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(DashboardSnapshotStore::class)->forget();
        $this->assertTrue($this->adminReadyQueueContains($incident));

        app(ServiceCaseAssignmentService::class)->reassign($incident, $agent, $admin);

        $fresh = $this->freshIncident($incident);

        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->shouldRemoveFromAdminReadyQueue($fresh));
        $this->assertFalse($this->adminReadyQueueContains($fresh));

        Carbon::setTestNow();
    }

    public function test_manual_assignment_broadcasts_remove_from_ready_queue(): void
    {
        Event::fake([ServiceCaseCreated::class]);

        $admin = $this->createAdminUser('admin@example.com');
        $agent = $this->createAgentUser('agent@example.com');
        $this->configureAssignmentSettings($admin->id);

        $order = $this->createValidatedOrder($admin, 'RD-QUEUE-LIVE-2');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(ServiceCaseAssignmentService::class)->reassign($incident, $agent, $admin);

        Event::assertDispatched(ServiceCaseCreated::class, function (ServiceCaseCreated $event) use ($incident): bool {
            return $event->incident->id === $incident->id
                && $event->removeFromList === true;
        });
    }

    public function test_order_id_edit_triggers_queue_membership_broadcast(): void
    {
        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        $admin = $this->createAdminUser('admin@example.com');
        $order = $this->createValidatedOrder($admin, 'RD-QUEUE-ORDER-EDIT');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        $this->actingAs($admin)
            ->put(route('orders.update', $order), [
                'order_id' => 'RDE-QUEUE-ORDER-EDIT',
                'serial_number' => $order->serial_number,
                'product_name' => $order->product_name,
                'device_model' => $order->device_model,
                'status' => 'active',
            ])
            ->assertRedirect(route('orders.show', $order));

        $fresh = $this->freshIncident($incident);

        $this->assertSame('RDE-QUEUE-ORDER-EDIT', $order->fresh()->order_id);
        $this->assertSame(OperationQueue::Hardware, app(OperationsQueueClassifier::class)->classify($fresh));
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->shouldRemoveFromAdminReadyQueue($fresh));

        $broadcastSpy->shouldHaveReceived('serviceCaseQueueMembershipChanged')
            ->once()
            ->withArgs(function (Incident $broadcastIncident, ?User $actor) use ($incident, $admin): bool {
                return $broadcastIncident->id === $incident->id
                    && $actor?->id === $admin->id;
            });
    }

    public function test_dashboard_assign_to_agent_returns_remove_row_for_actor(): void
    {
        $admin = $this->createAdminUser('admin@example.com');
        $agent = $this->createAgentUser('agent@example.com');
        $this->configureAssignmentSettings($admin->id);

        $order = $this->createValidatedOrder($admin, 'RD-QUEUE-WS-ASSIGN');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.assign', $incident), [
                'assigned_to_user_id' => $agent->id,
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'body' => 'Assign to agent from Ready Queue.',
            ])
            ->assertOk()
            ->assertJsonPath('refresh.remove_row.incident_id', $incident->id)
            ->assertJsonPath('refresh.replace_row', null);
    }

    public function test_customer360_assign_to_agent_returns_remove_row_for_actor(): void
    {
        $admin = $this->createAdminUser('admin@example.com');
        $agent = $this->createAgentUser('agent@example.com');
        $this->configureAssignmentSettings($admin->id);

        $order = $this->createValidatedOrder($admin, 'RD3447955');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::Customer->value,
                'action_type' => WorkspaceActionType::Assign->value,
                'assigned_to_user_id' => $agent->id,
                'body' => 'Assign to agent from Customer360 on dashboard.',
            ])
            ->assertOk()
            ->assertJsonPath('refresh.remove_row.incident_id', $incident->id)
            ->assertJsonPath('refresh.replace_row', null);
    }

    public function test_dashboard_assign_to_admin_still_returns_replace_row(): void
    {
        $admin = $this->createAdminUser('admin@example.com');
        $otherAdmin = $this->createAdminUser('other-admin@example.com');

        $order = $this->createValidatedOrder($admin, 'RD-QUEUE-WS-ADMIN');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.assign', $incident), [
                'assigned_to_user_id' => $otherAdmin->id,
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'body' => 'Reassign to another admin.',
            ])
            ->assertOk()
            ->assertJsonPath('refresh.replace_row.incident_id', $incident->id)
            ->assertJsonPath('refresh.remove_row', null);
    }

    private function configureAssignmentSettings(int $dayAdminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.night_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    private function createAdminUser(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createValidatedOrder(User $creator, string $orderId): Order
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
            'cashfree_payment_id' => 'cf_pay_'.$orderId,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $order->update(['radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        return $order;
    }

    private function createIncident(Order $order, User $creator, ?User $assignee = null): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => "Case {$order->order_id}",
            'description' => "Case {$order->order_id}.",
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function freshIncident(Incident $incident): Incident
    {
        return $incident->fresh([
            'order',
            'assignee.roles',
            'activeWaitingState',
            'supportAppointments',
        ]);
    }

    private function adminReadyQueueContains(Incident $incident): bool
    {
        return DashboardSnapshot::load()
            ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
            ->contains(fn (Incident $case): bool => $case->id === $incident->id);
    }
}
