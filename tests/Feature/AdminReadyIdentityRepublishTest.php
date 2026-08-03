<?php

namespace Tests\Feature;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\AuditLog;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\IncidentReferenceService;
use App\Services\OrderDeviceModelService;
use App\Services\OrderSerialService;
use App\Services\OrderTransactionService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Services\SettingService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminReadyIdentityRepublishTest extends TestCase
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
            'service_case_assignment.round_robin_enabled' => true,
            'smart_assignment.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(DashboardSnapshotStore::class)->forget();

        parent::tearDown();
    }

    public function test_normal_ready_flow_remains_visible(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('ready-admin@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto);

        $this->assertTrue($this->inAdminReady($incident));
    }

    public function test_edge_1_ready_manual_assign_validation_pass_republishes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('e1-admin@radium.local');
        $agent = $this->createAgent('e1-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
        // Incomplete Ready seed still has model identity; only serial is missing.
        $model = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $incident->order->update([
            'device_model_id' => $model->id,
            'device_model' => $model->name,
            'product_name' => $model->name,
        ]);
        $incident = $this->manualReassign($incident->fresh(['order']), $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));

        app(OrderSerialService::class)->assignSerialNumber($incident->order->fresh(), '7881953', $agent);

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertTrue($this->inAdminReady($fresh));
    }

    public function test_edge_2_manual_assign_again_without_validation_stays_hidden(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('e2-admin@radium.local');
        $agent = $this->createAgent('e2-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto);
        $incident = $this->manualReassign($incident, $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));

        $incident = $this->manualReassign($incident->fresh(), $agent, $admin);

        $fresh = $incident->fresh(['assignee.roles']);
        $this->assertFalse(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertFalse($this->inAdminReady($fresh));
        $this->assertSame(
            0,
            $this->validationPassedCountAfterLatestManual($fresh),
            'Assignment must not create validation_passed',
        );
    }

    public function test_edge_3_second_model_correction_keeps_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('e3-admin@radium.local');
        $agent = $this->createAgent('e3-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        DeviceModel::query()->firstOrCreate(['name' => 'MIS 100'], ['is_active' => true, 'display_order' => 99]);
        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953', modelName: 'MIS 100');
        $incident = $this->manualReassign($incident, $agent, $admin);

        $mfs = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        app(OrderDeviceModelService::class)->correctDeviceModel($incident->order->fresh(), $mfs, $agent);
        $this->assertTrue($this->inAdminReady($incident->fresh(['order', 'assignee.roles'])));

        $mis = DeviceModel::query()->where('name', 'MIS 100')->firstOrFail();
        app(OrderDeviceModelService::class)->correctDeviceModel($incident->order->fresh(), $mis, $agent);

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertTrue($this->inAdminReady($fresh));
    }

    public function test_edge_4_serial_removed_validation_fails_ready_disappears(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('e4-admin@radium.local');
        $agent = $this->createAgent('e4-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        DeviceModel::query()->firstOrCreate(['name' => 'MIS 100'], ['is_active' => true, 'display_order' => 99]);
        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953', modelName: 'MIS 100');
        $incident = $this->manualReassign($incident, $agent, $admin);

        $mfs = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        app(OrderDeviceModelService::class)->correctDeviceModel($incident->order->fresh(), $mfs, $agent);
        $this->assertTrue($this->inAdminReady($incident->fresh(['order', 'assignee.roles'])));

        $incident->order->update([
            'serial_number' => null,
            'updated_by' => $agent->id,
        ]);
        app(\App\Services\OrderIdentityLifecycleService::class)->afterIdentityChanged(
            order: $incident->order->fresh(),
            actor: $agent,
            source: 'order_admin_edit',
            serialChanged: true,
        );

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertFalse(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertFalse($this->inAdminReady($fresh));
    }

    public function test_edge_5_appointment_plus_identity_correction_keeps_appointment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('e5-admin@radium.local');
        $agent = $this->createAgent('e5-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
        $incident = $this->manualReassign($incident, $agent, $admin);

        $appointment = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9668152713',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        app(OrderSerialService::class)->assignSerialNumber(
            $incident->order->fresh(),
            '7881953',
            $agent,
        );

        $fresh = $incident->fresh(['order', 'assignee', 'supportAppointments']);

        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertTrue($fresh->hasActiveSupportAppointment());
        $this->assertSame($appointment->id, $fresh->supportAppointments->first()?->id);
        $this->assertSame(SupportAppointmentStatus::Scheduled, $fresh->supportAppointments->first()?->status);
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertTrue($this->inAdminReady($fresh));
    }

    public function test_edge_6_rd3470804_model_correction_republishes_ready_without_ownership_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('rd-admin@radium.local');
        $agent = $this->createAgent('gaurav-rd@radium.local');
        $this->configureShiftAdmin($admin->id);

        $mis = DeviceModel::query()->firstOrCreate(
            ['name' => 'MIS 100'],
            ['is_active' => true, 'display_order' => 99],
        );
        $incident = $this->createReadyCase(
            $admin,
            assignee: $admin,
            origin: AssignmentOrigin::Auto,
            serial: '7881953',
            modelName: 'MIS 100',
        );
        $this->assertTrue($this->inAdminReady($incident));

        app(ServiceCaseAutomationMonitorService::class)
            ->recordValidationPassed($incident->order->fresh(), $admin);

        $incident = $this->manualReassign($incident, $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));
        $this->assertSame($agent->id, $incident->fresh()->assigned_to_user_id);

        $mfs = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $this->assertNotSame($mis->id, $mfs->id);
        app(OrderDeviceModelService::class)->correctDeviceModel(
            $incident->order->fresh(),
            $mfs,
            $agent,
        );

        $fresh = $incident->fresh(['order', 'assignee.roles']);

        $this->assertSame($agent->id, $fresh->assigned_to_user_id, 'Ownership unchanged');
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertTrue($this->inAdminReady($fresh), 'Ready republished after identity validation');
        $this->assertGreaterThan(0, $this->validationPassedCountAfterLatestManual($fresh));
    }

    public function test_assignment_notes_do_not_create_repeat_validation_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('notes-admin@radium.local');
        $agent = $this->createAgent('notes-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto);
        app(ServiceCaseAutomationMonitorService::class)
            ->recordValidationPassed($incident->order->fresh(), $admin);
        $before = AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED)
            ->count();

        $incident = $this->manualReassign($incident, $agent, $admin);
        $this->manualReassign($incident->fresh(), $agent, $admin);

        $after = AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED)
            ->count();

        $this->assertSame($before, $after);
        $this->assertFalse($this->inAdminReady($incident->fresh(['assignee.roles'])));
    }

    public function test_wiring_serial_does_not_republish_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('wire-admin@radium.local');
        $agent = $this->createAgent('wire-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
        $incident = $this->manualReassign($incident, $agent, $admin);

        $incident->order->update([
            'serial_number' => 'NA',
            'updated_by' => $agent->id,
        ]);
        app(\App\Services\OrderIdentityLifecycleService::class)->afterIdentityChanged(
            order: $incident->order->fresh(),
            actor: $agent,
            source: 'manual_serial_entry',
            serialChanged: true,
        );

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertFalse(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertFalse($this->inAdminReady($fresh));
    }

    public function test_missing_serial_does_not_republish_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('missing-admin@radium.local');
        $agent = $this->createAgent('missing-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
        $incident = $this->manualReassign($incident, $agent, $admin);

        app(\App\Services\OrderIdentityLifecycleService::class)->afterIdentityChanged(
            order: $incident->order->fresh(),
            actor: $agent,
            source: 'device_model_assigned',
        );

        $this->assertFalse(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($incident->fresh(['assignee.roles'])));
        $this->assertFalse($this->inAdminReady($incident));
    }

    public function test_validation_fail_does_not_republish_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('fail-admin@radium.local');
        $agent = $this->createAgent('fail-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
        $incident = $this->manualReassign($incident, $agent, $admin);

        $incident->order->update([
            'serial_number' => 'NOT-A-VALID-SERIAL-XXX',
            'updated_by' => $agent->id,
        ]);
        app(\App\Services\OrderIdentityLifecycleService::class)->afterIdentityChanged(
            order: $incident->order->fresh(),
            actor: $agent,
            source: 'manual_serial_entry',
            serialChanged: true,
        );

        $this->assertFalse(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($incident->fresh(['assignee.roles'])));
        $this->assertFalse($this->inAdminReady($incident));
    }

    public function test_pending_radiumbox_does_not_appear_in_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('rb-admin@radium.local');
        $agent = $this->createAgent('rb-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953');
        // Remove sync so RadiumBox verification is pending.
        app(RadiumBoxOrderEnrichmentSyncStore::class)->forget($incident->order_id);
        $incident = $this->manualReassign($incident, $agent, $admin);

        $this->assertFalse($this->inAdminReady($incident->fresh(['order', 'assignee.roles'])));
    }

    public function test_closed_and_transaction_id_never_appear_in_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('sr-admin@radium.local');
        $agent = $this->createAgent('sr-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        DeviceModel::query()->firstOrCreate(['name' => 'MIS 100'], ['is_active' => true, 'display_order' => 99]);
        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953', modelName: 'MIS 100');
        $incident = $this->manualReassign($incident, $agent, $admin);

        $mfs = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        app(OrderDeviceModelService::class)->correctDeviceModel($incident->order->fresh(), $mfs, $agent);
        $this->assertTrue($this->inAdminReady($incident->fresh()));

        app(OrderTransactionService::class)->assignTransactionId(
            order: $incident->order->fresh(),
            transactionId: 'TXN-READY-REPUBLISH-1',
            actor: $admin,
            broadcast: false,
        );

        app(DashboardSnapshotStore::class)->forget();
        $withTxn = $incident->fresh(['order']);
        $this->assertFalse($this->inAdminReady($withTxn));

        $withTxn->update(['status' => IncidentStatus::Closed]);
        app(DashboardSnapshotStore::class)->forget();
        $this->assertFalse($this->inAdminReady($withTxn->fresh(['order'])));
    }

    public function test_inquiry_and_hardware_cases_never_appear_in_admin_ready_overlay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('type-admin@radium.local');
        $agent = $this->createAgent('type-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $inquiry = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto);
        $inquiry->order->update(['order_id' => 'INQ-'.uniqid()]);
        $inquiry = $this->manualReassign($inquiry->fresh(['order']), $agent, $admin);
        $this->assertFalse($this->inAdminReady($inquiry->fresh(['order', 'assignee.roles'])));

        $hardware = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto);
        $hardware->order->update(['order_id' => 'RDE'.uniqid()]);
        $hardware = $this->manualReassign($hardware->fresh(['order']), $agent, $admin);
        $this->assertFalse($this->inAdminReady($hardware->fresh(['order', 'assignee.roles'])));
    }

    private function createReadyCase(
        User $actor,
        User $assignee,
        AssignmentOrigin $origin,
        ?string $serial = '7881953',
        string $modelName = 'MFS110',
    ): Incident {
        $model = DeviceModel::query()->where('name', $modelName)->first()
            ?? DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-READY-REP-'.uniqid(),
            'serial_number' => $serial,
            'product_name' => $model->name,
            'device_model' => $model->name,
            'device_model_id' => $serial ? $model->id : null,
            'cashfree_payment_id' => 'cf_'.uniqid(),
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        if ($serial) {
            $this->markSynced($order->id);
        }

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Ready republish case',
            'description' => 'Ready republish case',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee->id,
            'assignment_origin' => $origin,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return $incident->fresh(['order', 'assignee.roles']);
    }

    private function manualReassign(Incident $incident, User $assignee, User $actor): Incident
    {
        return app(ServiceCaseAssignmentService::class)->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: [
                'assignment_override' => true,
                'override_reason' => 'manual_reassign',
            ],
            event: 'service_case.reassigned',
            assignmentOrigin: AssignmentOrigin::Manual,
        )->fresh(['order', 'assignee.roles']);
    }

    private function inAdminReady(Incident $incident): bool
    {
        app(DashboardSnapshotStore::class)->forget();

        return DashboardSnapshot::load()
            ->incidentsForQueue(OperationQueue::ActionRequired->value)
            ->contains(fn (Incident $row): bool => $row->id === $incident->id);
    }

    private function validationPassedCountAfterLatestManual(Incident $incident): int
    {
        $manualId = AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->whereIn('event', ['service_case.assigned', 'service_case.reassigned', 'service_case.escalated'])
            ->where('new_values->assignment_origin', AssignmentOrigin::Manual->value)
            ->orderByDesc('id')
            ->value('id');

        if ($manualId === null) {
            return 0;
        }

        return AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED)
            ->where('id', '>', $manualId)
            ->count();
    }

    private function markSynced(int $orderId): void
    {
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($orderId, [
            'lookup_result' => 'data_received',
        ]);
    }

    private function createAdmin(string $email): User
    {
        $admin = User::factory()->create(['email' => $email, 'is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createAgent(string $email): User
    {
        $agent = User::factory()->create(['email' => $email, 'is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    private function configureShiftAdmin(int $adminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $adminId,
            'assignment.night_shift_admin_user_id' => (string) $adminId,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }
}
