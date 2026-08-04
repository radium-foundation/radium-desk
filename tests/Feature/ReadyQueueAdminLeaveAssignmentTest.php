<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkSessionOrigin;
use App\Models\AuditLog;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\Assignment\ReadyQueueAdminAssignmentService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\PresenceEngineService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReadyQueueAdminLeaveAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        config([
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.ready_queue_pickup_batch_size' => 25,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
            'name' => 'Ira',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        Carbon::setTestNow(Carbon::parse('2026-08-04 14:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_primary_admin_on_leave_assigns_fallback(): void
    {
        $primary = $this->admin('avinash@test.com', 'Avinash');
        $fallback = $this->admin('dileep@test.com', 'Dileep');
        $this->configureDayAdmins($primary->id, $fallback->id);

        $this->approveLeave($primary);

        $actor = $this->admin('actor@test.com', 'Actor');
        $incident = $this->createUnassignedReadyIncident($actor);

        $result = app(ServiceCaseAssignmentService::class)
            ->assignToShiftAdminAfterValidation($incident, $actor);

        $this->assertSame($fallback->id, $result->assigned_to_user_id);
        $this->assertNotSame($primary->id, $result->assigned_to_user_id);
    }

    public function test_primary_and_first_fallback_on_leave_assigns_second_fallback(): void
    {
        $primary = $this->admin('avinash@test.com', 'Avinash');
        $fallback1 = $this->admin('dileep@test.com', 'Dileep');
        $fallback2 = $this->admin('shipra@test.com', 'Shipra');
        $this->configureDayAdmins($primary->id, $fallback1->id, $fallback2->id);

        $this->approveLeave($primary);
        $this->approveLeave($fallback1);

        $actor = $this->admin('actor@test.com', 'Actor');
        $incident = $this->createUnassignedReadyIncident($actor);

        $result = app(ServiceCaseAssignmentService::class)
            ->assignToShiftAdminAfterValidation($incident, $actor);

        $this->assertSame($fallback2->id, $result->assigned_to_user_id);
    }

    public function test_all_admins_on_leave_remains_with_ira_ready_queue_and_audits(): void
    {
        $primary = $this->admin('avinash@test.com', 'Avinash');
        $fallback1 = $this->admin('dileep@test.com', 'Dileep');
        $fallback2 = $this->admin('shipra@test.com', 'Shipra');
        $this->configureDayAdmins($primary->id, $fallback1->id, $fallback2->id);

        $this->approveLeave($primary);
        $this->approveLeave($fallback1, LeaveDuration::HalfDay);
        $this->approveLeave($fallback2);

        $actor = $this->admin('actor@test.com', 'Actor');
        $incident = $this->createUnassignedReadyIncident($actor);

        $result = app(ServiceCaseAssignmentService::class)
            ->assignToShiftAdminAfterValidation($incident, $actor);

        $this->assertNull($result->assigned_to_user_id);

        $audit = AuditLog::query()
            ->where('event', ReadyQueueAdminAssignmentService::NO_ELIGIBLE_ADMIN_EVENT)
            ->where('auditable_id', $incident->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(
            ReadyQueueAdminAssignmentService::NO_ELIGIBLE_ADMIN_REASON,
            $audit->new_values['reason'] ?? null,
        );
        $this->assertSame('ira', $audit->new_values['ready_queue_retained_by'] ?? null);
    }

    public function test_leave_ends_periodic_pickup_assigns_oldest_pending_case(): void
    {
        $primary = $this->admin('avinash@test.com', 'Avinash');
        $fallback = $this->admin('dileep@test.com', 'Dileep');
        $this->configureDayAdmins($primary->id, $fallback->id);

        $this->approveLeave($primary);
        $this->approveLeave($fallback);

        $actor = $this->admin('actor@test.com', 'Actor');
        $older = $this->createUnassignedReadyIncident($actor, 'RD-OLD-1', '7881953');
        $newer = $this->createUnassignedReadyIncident($actor, 'RD-NEW-1', '7881954');

        app(ServiceCaseAssignmentService::class)->assignToShiftAdminAfterValidation($older, $actor);
        app(ServiceCaseAssignmentService::class)->assignToShiftAdminAfterValidation($newer, $actor);

        $this->assertNull($older->fresh()->assigned_to_user_id);
        $this->assertNull($newer->fresh()->assigned_to_user_id);

        LeaveRequest::query()->where('user_id', $fallback->id)->delete();

        Artisan::call('service-cases:process-automation-pending');

        $this->assertSame($fallback->id, $older->fresh()->assigned_to_user_id);
        $this->assertSame($fallback->id, $newer->fresh()->assigned_to_user_id);
        $this->assertTrue($older->id < $newer->id);
    }

    public function test_user_login_triggers_ready_queue_pickup(): void
    {
        $primary = $this->admin('avinash@test.com', 'Avinash');
        $fallback = $this->admin('dileep@test.com', 'Dileep');
        $this->configureDayAdmins($primary->id, $fallback->id);

        $this->approveLeave($primary);
        $this->approveLeave($fallback);

        $actor = $this->admin('actor@test.com', 'Actor');
        $incident = $this->createUnassignedReadyIncident($actor);

        app(ServiceCaseAssignmentService::class)->assignToShiftAdminAfterValidation($incident, $actor);
        $this->assertNull($incident->fresh()->assigned_to_user_id);

        LeaveRequest::query()->where('user_id', $fallback->id)->delete();

        app(PresenceEngineService::class)->startSession($fallback, now(), WorkSessionOrigin::Browser);

        $this->assertSame($fallback->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_pickup_never_modifies_already_assigned_cases(): void
    {
        $primary = $this->admin('avinash@test.com', 'Avinash');
        $otherOwner = $this->admin('owner@test.com', 'Owner');
        $this->configureDayAdmins($primary->id);

        $actor = $this->admin('actor@test.com', 'Actor');
        $owned = $this->createUnassignedReadyIncident($actor, 'RD-OWNED-1', '7881953');
        $owned->update(['assigned_to_user_id' => $otherOwner->id]);

        $pending = $this->createUnassignedReadyIncident($actor, 'RD-PEND-1', '7881954');

        $assigned = app(ReadyQueueAdminAssignmentService::class)->pickupUnassignedReadyQueueCases();

        $this->assertSame(1, $assigned);
        $this->assertSame($otherOwner->id, $owned->fresh()->assigned_to_user_id);
        $this->assertSame($primary->id, $pending->fresh()->assigned_to_user_id);
    }

    public function test_ready_queue_assigns_primary_when_not_on_leave(): void
    {
        $primary = $this->admin('avinash@test.com', 'Avinash');
        $fallback = $this->admin('dileep@test.com', 'Dileep');
        $this->configureDayAdmins($primary->id, $fallback->id);

        $actor = $this->admin('actor@test.com', 'Actor');
        $incident = $this->createUnassignedReadyIncident($actor);

        $result = app(ServiceCaseAssignmentService::class)
            ->assignToShiftAdminAfterValidation($incident, $actor);

        $this->assertSame($primary->id, $result->assigned_to_user_id);

        $audit = AuditLog::query()
            ->where('event', 'service_case.assigned')
            ->where('auditable_id', $incident->id)
            ->first();

        $this->assertTrue($audit?->new_values['assignment_override'] ?? false);
        $this->assertSame('shift_admin', $audit?->new_values['override_reason'] ?? null);
    }

    public function test_support_resolve_assignee_or_null_still_ignores_leave(): void
    {
        $primary = $this->admin('avinash@test.com', 'Avinash');
        $fallback = $this->admin('dileep@test.com', 'Dileep');
        $this->configureDayAdmins($primary->id, $fallback->id);

        $this->approveLeave($primary);

        $resolved = app(ServiceCaseAssignmentService::class)->resolveAssigneeOrNull(now());

        $this->assertSame($primary->id, $resolved?->id);
    }

    private function configureDayAdmins(int $dayAdminId, int $fallback1Id = 0, int $fallback2Id = 0): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.night_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.fallback_admin_1_user_id' => $fallback1Id > 0 ? (string) $fallback1Id : '',
            'assignment.fallback_admin_2_user_id' => $fallback2Id > 0 ? (string) $fallback2Id : '',
        ]);
    }

    private function admin(string $email, string $name): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'name' => $name,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function approveLeave(User $user, LeaveDuration $duration = LeaveDuration::FullDay): void
    {
        LeaveRequest::query()->create([
            'user_id' => $user->id,
            'start_date' => '2026-08-04',
            'end_date' => '2026-08-04',
            'reason' => 'Approved leave',
            'duration' => $duration,
            'status' => LeaveRequestStatus::Approved,
        ]);
    }

    private function createUnassignedReadyIncident(
        User $actor,
        string $orderId = 'RD-READY-1',
        string $serial = '7881953',
    ): Incident {
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serial,
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Ready case {$orderId}",
            'description' => "Ready case {$orderId}.",
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->assertTrue(
            app(OperationsQueueClassifier::class)->isReadyForReferenceEntry($incident->fresh(['order'])),
            'Fixture must qualify as Ready Queue eligible.',
        );

        return $incident;
    }
}
