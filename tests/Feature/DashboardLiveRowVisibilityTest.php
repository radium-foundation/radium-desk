<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\OrderStatus;
use App\Enums\WaitingReason;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardLiveRowVisibilityService;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardBroadcastService;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentWaitingStateService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DashboardLiveRowVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $dayAdmin = User::factory()->create(['email' => 'day-admin-row-visibility@test.com']);
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

    public function test_waiting_state_broadcast_adds_row_for_agent_waiting_tab_and_removes_for_admin_ready_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('Waiting Admin');
        $agent = $this->createAgent('Waiting Agent');
        $viewer = $this->createAdmin('Waiting Viewer');

        $incident = $this->createIncident('RD-WAIT-ROW-1', $admin, $agent);

        Event::fake([ServiceCaseCreated::class]);

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $admin,
        );

        Event::assertDispatched(ServiceCaseCreated::class, function (ServiceCaseCreated $event) use ($agent, $incident): bool {
            return $event->recipient->id === $agent->id
                && $event->incident->id === $incident->id
                && $event->incidentQueue === OperationQueue::WaitingCustomer->value
                && ($event->listActions[DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER] ?? null) === DashboardLiveRowVisibilityService::ACTION_ADD
                && ($event->listActions[DashboardPersonalizationService::QUEUE_MY_WORK] ?? null) === DashboardLiveRowVisibilityService::ACTION_ADD
                && filled($event->rowHtml);
        });

        Event::assertDispatched(ServiceCaseCreated::class, function (ServiceCaseCreated $event) use ($viewer, $incident): bool {
            return $event->recipient->id === $viewer->id
                && $event->incident->id === $incident->id
                && ($event->listActions[DashboardPersonalizationService::QUEUE_ACTION_REQUIRED] ?? null) === DashboardLiveRowVisibilityService::ACTION_REMOVE
                && ($event->listActions[DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER] ?? null) === DashboardLiveRowVisibilityService::ACTION_ADD;
        });

        Carbon::setTestNow();
    }

    public function test_waiting_state_clear_broadcast_removes_row_from_waiting_tab(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('Clear Waiting Admin');
        $agent = $this->createAgent('Clear Waiting Agent');

        $incident = $this->createIncident('RD-WAIT-CLEAR-1', $admin, $agent);

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $admin,
        );

        app(DashboardSnapshotStore::class)->forget();

        Event::fake([ServiceCaseCreated::class]);

        app(IncidentWaitingStateService::class)->clear(
            incident: $incident->fresh(['activeWaitingState']),
            actor: $admin,
        );

        Event::assertDispatched(ServiceCaseCreated::class, function (ServiceCaseCreated $event) use ($agent, $incident): bool {
            return $event->recipient->id === $agent->id
                && $event->incident->id === $incident->id
                && ($event->listActions[DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER] ?? null) === DashboardLiveRowVisibilityService::ACTION_REMOVE;
        });

        Carbon::setTestNow();
    }

    public function test_manual_assignment_broadcast_removes_row_from_ready_queue_for_other_admins(): void
    {
        $admin = $this->createAdmin('Assign Admin');
        $agent = $this->createAgent('Assign Agent');
        $viewer = $this->createAdmin('Assign Viewer');

        $order = Order::query()->create([
            'order_id' => 'RD-ASSIGN-ROW-1',
            'serial_number' => 'SN-ASSIGN-ROW-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_assign_row',
            'status' => OrderStatus::Active,
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-ASSIGN-ROW-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Assign row visibility',
            'description' => 'Assign row visibility',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $admin->id,
            'created_by' => $admin->id,
        ]);

        Event::fake([ServiceCaseCreated::class]);

        app(\App\Services\ServiceCaseAssignmentService::class)->reassign($incident, $agent, $admin);

        Event::assertDispatched(ServiceCaseCreated::class, function (ServiceCaseCreated $event) use ($viewer, $incident): bool {
            return $event->recipient->id === $viewer->id
                && $event->incident->id === $incident->id
                && ($event->listActions[DashboardPersonalizationService::QUEUE_ACTION_REQUIRED] ?? null) === DashboardLiveRowVisibilityService::ACTION_REMOVE;
        });

        Event::assertDispatched(ServiceCaseCreated::class, function (ServiceCaseCreated $event) use ($agent, $incident): bool {
            return $event->recipient->id === $agent->id
                && $event->incident->id === $incident->id
                && ($event->listActions[DashboardPersonalizationService::QUEUE_MY_WORK] ?? null) === DashboardLiveRowVisibilityService::ACTION_ADD;
        });
    }

    public function test_resolved_status_broadcast_removes_row_from_operations_queues(): void
    {
        $admin = $this->createAdmin('Resolve Admin');
        $viewer = $this->createAdmin('Resolve Viewer');

        $incident = $this->createIncident('RD-RESOLVE-ROW-1', $admin, $admin);

        Event::fake([\App\Events\Dashboard\ServiceCaseResolved::class]);

        app(\App\Services\ServiceCaseStatusService::class)->updateStatus(
            incident: $incident,
            status: IncidentStatus::Resolved,
            actor: $admin,
        );

        Event::assertDispatched(\App\Events\Dashboard\ServiceCaseResolved::class, function ($event) use ($viewer, $incident): bool {
            return $event->recipient->id === $viewer->id
                && $event->incident->id === $incident->id
                && ($event->listActions[DashboardPersonalizationService::QUEUE_ACTION_REQUIRED] ?? null) === DashboardLiveRowVisibilityService::ACTION_REMOVE
                && ($event->listActions[DashboardPersonalizationService::QUEUE_ATTENTION] ?? null) === DashboardLiveRowVisibilityService::ACTION_REMOVE;
        });
    }

    public function test_row_broadcast_payload_omits_html_when_only_removals_are_needed(): void
    {
        $admin = $this->createAdmin('Payload Admin');
        $incident = $this->createIncident('RD-PAYLOAD-1', $admin, $admin);

        app(\App\Services\ServiceCaseStatusService::class)->updateStatus(
            incident: $incident,
            status: IncidentStatus::Resolved,
            actor: $admin,
        );

        app(DashboardSnapshotStore::class)->forget();

        $payload = app(DashboardLiveRowVisibilityService::class)->rowBroadcastPayload(
            $incident->fresh(['order', 'assignee.roles', 'activeWaitingState', 'activeBusinessHold', 'supportAppointments']),
            $admin,
        );

        $this->assertSame(OperationQueue::Completed->value, $payload['queue']);
        $this->assertNotContains(
            DashboardLiveRowVisibilityService::ACTION_ADD,
            $payload['list_actions'],
        );
        $this->assertNotContains(
            DashboardLiveRowVisibilityService::ACTION_UPDATE,
            $payload['list_actions'],
        );
        $this->assertNull($payload['html']);
    }

    public function test_broadcast_event_payload_includes_list_actions_map(): void
    {
        Event::fake([ServiceCaseCreated::class]);

        $actor = $this->createAdmin('Event Payload Actor');
        $viewer = $this->createAdmin('Event Payload Viewer');
        $incident = $this->createIncident('RD-EVENT-PAYLOAD-1', $actor, $actor);

        app(DashboardBroadcastService::class)->serviceCaseQueueMembershipChanged($incident->fresh(), $actor);

        Event::assertDispatched(ServiceCaseCreated::class, function (ServiceCaseCreated $event) use ($viewer): bool {
            return $event->recipient->id === $viewer->id
                && is_array($event->listActions)
                && array_key_exists(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED, $event->listActions);
        });
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
