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
use App\Services\Operations\OperationsQueueClassifier;
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
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
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

    public function test_edge_1_ready_manual_assign_meaningful_serial_republishes(): void
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
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
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

    public function test_edge_3_second_model_correction_keeps_ready_after_manual_identity_edit(): void
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

    public function test_edge_4_serial_removed_under_manual_ownership_stays_hidden(): void
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
        $this->assertTrue($this->inAdminReady($fresh), 'Meaningful model correction republishes Admin Ready');
    }

    public function test_sc28000_radiumbox_auto_update_does_not_republish_under_manual_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 14:10:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('avinash-sc28000@radium.local');
        $agent = $this->createAgent('jayram-sc28000@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase(
            $admin,
            assignee: $admin,
            origin: AssignmentOrigin::Auto,
            serial: null,
        );
        $model = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $incident->order->update([
            'device_model_id' => $model->id,
            'device_model' => $model->name,
            'product_name' => $model->name,
            'order_id' => 'RD3476656-SC28000',
        ]);

        $incident = $this->manualReassign($incident->fresh(['order']), $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->hasManualSupportOwnership($incident));

        Carbon::setTestNow(Carbon::parse('2026-08-07 12:56:00', 'Asia/Kolkata'));

        // Background RadiumBox path applies serial without human OrderSerialService.
        $incident->order->update([
            'serial_number' => '6540662',
            'updated_by' => 1,
        ]);
        $this->markSynced($incident->order_id);

        app(\App\Services\OrderIdentityLifecycleService::class)->afterIdentityChanged(
            order: $incident->order->fresh(),
            actor: $admin,
            source: 'radiumbox_enrichment',
            serialChanged: true,
        );

        $fresh = $incident->fresh(['order', 'assignee.roles']);

        $this->assertSame($agent->id, $fresh->assigned_to_user_id, 'SC28000 owner must be preserved');
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertTrue(
            app(OperationsQueueClassifier::class)->isReadyForReferenceEntry($fresh),
            'Internal Ready eligibility may pass; Admin Ready visibility must not',
        );
        $this->assertFalse(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertFalse($this->inAdminReady($fresh));
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->shouldRemoveFromAdminReadyQueue($fresh));
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('auditable_type', $fresh->getMorphClass())
                ->where('auditable_id', $fresh->id)
                ->where('event', ServiceCaseAssignmentService::MANUAL_IDENTITY_READY_REPUBLISH_EVENT)
                ->count(),
        );
    }

    public function test_blank_to_serial_republishes_under_manual_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('blank-serial-admin@radium.local');
        $agent = $this->createAgent('blank-serial-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
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
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertTrue($this->inAdminReady($fresh));
    }

    public function test_serial_a_to_serial_b_republishes_under_manual_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('serial-ab-admin@radium.local');
        $agent = $this->createAgent('serial-ab-agent@radium.local');
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953');
        $incident = $this->manualReassign($incident, $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->actingAs($agent)
            ->patchJson(route('incidents.workspace.correct-serial-number', $incident), [
                'serial_number' => '9655721',
                'reason' => 'Customer confirmed the correct serial on a verified call.',
                'workspace_context' => 'customer',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertSame('9655721', $fresh->order->serial_number);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertTrue($this->inAdminReady($fresh));
    }

    public function test_blank_to_model_republishes_under_manual_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('blank-model-admin@radium.local');
        $agent = $this->createAgent('blank-model-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953');
        $incident->order->update([
            'device_model_id' => null,
            'device_model' => null,
            'product_name' => null,
        ]);
        $incident = $this->manualReassign($incident->fresh(['order']), $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));

        $mfs = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        app(OrderDeviceModelService::class)->assignDeviceModel($incident->order->fresh(), $mfs, $agent);

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertTrue($this->inAdminReady($fresh));
    }

    public function test_model_a_to_model_b_republishes_under_manual_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('model-ab-admin@radium.local');
        $agent = $this->createAgent('model-ab-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        DeviceModel::query()->firstOrCreate(['name' => 'MIS 100'], ['is_active' => true, 'display_order' => 99]);
        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953', modelName: 'MIS 100');
        $incident = $this->manualReassign($incident, $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));

        $mfs = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        app(OrderDeviceModelService::class)->correctDeviceModel($incident->order->fresh(), $mfs, $agent);

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertTrue($this->inAdminReady($fresh));
    }

    public function test_unchanged_serial_does_not_republish_under_manual_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('same-serial-admin@radium.local');
        $agent = $this->createAgent('same-serial-agent@radium.local');
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953');
        $incident = $this->manualReassign($incident, $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->actingAs($agent)
            ->patchJson(route('incidents.workspace.correct-serial-number', $incident), [
                'serial_number' => '7881953',
                'reason' => 'No actual change.',
                'workspace_context' => 'customer',
            ])
            ->assertUnprocessable();

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertFalse($this->inAdminReady($fresh));
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('auditable_type', $fresh->getMorphClass())
                ->where('auditable_id', $fresh->id)
                ->where('event', ServiceCaseAssignmentService::MANUAL_IDENTITY_READY_REPUBLISH_EVENT)
                ->count(),
        );
    }

    public function test_unchanged_model_does_not_republish_under_manual_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('same-model-admin@radium.local');
        $agent = $this->createAgent('same-model-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953');
        $incident = $this->manualReassign($incident, $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));

        $mfs = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        app(OrderDeviceModelService::class)->assignDeviceModel($incident->order->fresh(), $mfs, $agent);

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertFalse($this->inAdminReady($fresh));
    }

    public function test_customer_info_edit_does_not_republish_under_manual_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('customer-edit-admin@radium.local');
        $agent = $this->createAgent('customer-edit-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: '7881953');
        $incident = $this->manualReassign($incident, $agent, $admin);
        $this->assertFalse($this->inAdminReady($incident));

        $incident->order->update([
            'customer_name' => 'Updated Customer',
            'customer_phone' => '9999999999',
            'customer_email' => 'updated@example.com',
            'updated_by' => $agent->id,
        ]);

        $fresh = $incident->fresh(['order', 'assignee.roles']);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertFalse($this->inAdminReady($fresh));
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('auditable_type', $fresh->getMorphClass())
                ->where('auditable_id', $fresh->id)
                ->where('event', ServiceCaseAssignmentService::MANUAL_IDENTITY_READY_REPUBLISH_EVENT)
                ->count(),
        );
    }

    public function test_auto_assigned_incident_still_appears_in_admin_ready_after_validation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:56:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('auto-ready-admin@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase(
            $admin,
            assignee: $admin,
            origin: AssignmentOrigin::Auto,
            serial: null,
        );
        $model = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $incident->order->update([
            'device_model_id' => $model->id,
            'device_model' => $model->name,
            'product_name' => $model->name,
        ]);

        app(OrderSerialService::class)->assignSerialNumber($incident->order->fresh(), '7881953', $admin);

        $fresh = $incident->fresh(['order', 'assignee.roles']);

        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Auto, $fresh->assignment_origin);
        $this->assertFalse(app(ServiceCaseAssignmentService::class)->hasManualSupportOwnership($fresh));
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh));
        $this->assertTrue($this->inAdminReady($fresh));
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

    public function test_prefetch_zero_candidates_issues_no_audit_queries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('prefetch-m0-admin@radium.local');
        $this->configureShiftAdmin($admin->id);

        // Non-manual Admin Ready cases: overlay short-circuits with zero audit SQL.
        $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto);
        $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto);

        app(DashboardSnapshotStore::class)->forget();

        $auditQueryCount = $this->countAuditLogQueries(function (): void {
            DashboardSnapshot::load()
                ->incidentsForQueue(OperationQueue::ActionRequired->value);
        });

        $this->assertSame(0, $auditQueryCount);
    }

    public function test_prefetch_batches_at_most_two_audit_queries_for_many_candidates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('prefetch-m-admin@radium.local');
        $agent = $this->createAgent('prefetch-m-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $hidden = [];
        $visible = [];

        for ($i = 0; $i < 4; $i++) {
            $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto);
            $hidden[] = $this->manualReassign($incident, $agent, $admin);
        }

        for ($i = 0; $i < 3; $i++) {
            $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
            $model = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
            $incident->order->update([
                'device_model_id' => $model->id,
                'device_model' => $model->name,
                'product_name' => $model->name,
            ]);
            $incident = $this->manualReassign($incident->fresh(['order']), $agent, $admin);
            app(OrderSerialService::class)->assignSerialNumber($incident->order->fresh(), '78819'.(50 + $i), $agent);
            $visible[] = $incident->fresh(['order', 'assignee.roles']);
        }

        $this->assertCount(4, $hidden);
        $this->assertCount(3, $visible);
        $this->assertGreaterThanOrEqual(5, count($hidden) + count($visible));

        app(DashboardSnapshotStore::class)->forget();

        $auditQueryCount = $this->countAuditLogQueries(function (): void {
            DashboardSnapshot::load()
                ->incidentsForQueue(OperationQueue::ActionRequired->value);
        });

        $this->assertLessThanOrEqual(2, $auditQueryCount);
        $this->assertGreaterThan(0, $auditQueryCount);

        foreach ($hidden as $incident) {
            $this->assertFalse($this->inAdminReady($incident->fresh(['order', 'assignee.roles'])));
        }

        foreach ($visible as $incident) {
            $this->assertTrue($this->inAdminReady($incident->fresh(['order', 'assignee.roles'])));
        }
    }

    public function test_prefetch_visibility_matches_per_incident_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('prefetch-parity-admin@radium.local');
        $agent = $this->createAgent('prefetch-parity-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $hidden = $this->manualReassign(
            $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto),
            $agent,
            $admin,
        );

        $incomplete = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
        $model = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $incomplete->order->update([
            'device_model_id' => $model->id,
            'device_model' => $model->name,
            'product_name' => $model->name,
        ]);
        $republished = $this->manualReassign($incomplete->fresh(['order']), $agent, $admin);
        app(OrderSerialService::class)->assignSerialNumber($republished->order->fresh(), '7881959', $agent);
        $republished = $republished->fresh(['order', 'assignee.roles', 'supportAppointments', 'activeWaitingState']);

        $autoVisible = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto);

        $incidents = collect([
            $hidden->fresh(['order', 'assignee.roles', 'supportAppointments', 'activeWaitingState']),
            $republished,
            $autoVisible->fresh(['order', 'assignee.roles', 'supportAppointments', 'activeWaitingState']),
        ]);

        $perIncident = app()->make(ServiceCaseAssignmentService::class);
        $expected = [];
        foreach ($incidents as $incident) {
            $expected[(int) $incident->id] = $perIncident->isVisibleInAdminReadyQueue($incident);
        }

        $batched = app()->make(ServiceCaseAssignmentService::class);
        $batched->prefetchAdminReadyVisibility($incidents);
        $actual = [];
        foreach ($incidents as $incident) {
            $actual[(int) $incident->id] = $batched->isVisibleInAdminReadyQueue($incident);
        }

        $this->assertSame($expected, $actual);
        $this->assertFalse($expected[(int) $hidden->id]);
        $this->assertTrue($expected[(int) $republished->id]);
        $this->assertTrue($expected[(int) $autoVisible->id]);
    }

    public function test_prefetch_sc28000_stays_hidden_until_manual_republish(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 14:10:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('prefetch-sc28000-admin@radium.local');
        $agent = $this->createAgent('prefetch-sc28000-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
        $model = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $incident->order->update([
            'device_model_id' => $model->id,
            'device_model' => $model->name,
            'product_name' => $model->name,
        ]);
        $incident = $this->manualReassign($incident->fresh(['order']), $agent, $admin);

        $incident->order->update(['serial_number' => '6540662', 'updated_by' => 1]);
        $this->markSynced($incident->order_id);
        app(\App\Services\OrderIdentityLifecycleService::class)->afterIdentityChanged(
            order: $incident->order->fresh(),
            actor: $admin,
            source: 'radiumbox_enrichment',
            serialChanged: true,
        );

        $fresh = $incident->fresh(['order', 'assignee.roles', 'supportAppointments', 'activeWaitingState']);
        $service = app()->make(ServiceCaseAssignmentService::class);
        $service->prefetchAdminReadyVisibility([$fresh]);

        $this->assertTrue(app(OperationsQueueClassifier::class)->isReadyForReferenceEntry($fresh));
        $this->assertFalse($service->isVisibleInAdminReadyQueue($fresh));
        $this->assertFalse($this->inAdminReady($fresh));

        DeviceModel::query()->firstOrCreate(['name' => 'MIS 100'], ['is_active' => true, 'display_order' => 99]);
        $mis = DeviceModel::query()->where('name', 'MIS 100')->firstOrFail();
        app(OrderDeviceModelService::class)->correctDeviceModel($fresh->order->fresh(), $mis, $agent);
        $after = $fresh->fresh(['order', 'assignee.roles', 'supportAppointments', 'activeWaitingState']);

        $serviceAfter = app()->make(ServiceCaseAssignmentService::class);
        $serviceAfter->prefetchAdminReadyVisibility([$after]);
        $this->assertTrue($serviceAfter->isVisibleInAdminReadyQueue($after));
        $this->assertTrue($this->inAdminReady($after));
    }

    public function test_prefetch_unrelated_audit_events_do_not_qualify(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('prefetch-unrelated-admin@radium.local');
        $agent = $this->createAgent('prefetch-unrelated-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $incident = $this->manualReassign(
            $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto),
            $agent,
            $admin,
        );

        // Later non-manual ownership + validation_passed must not unlock Admin Ready.
        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => 'service_case.reassigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => [],
            'new_values' => [
                'assigned_to_user_id' => $agent->id,
                'assignment_origin' => AssignmentOrigin::Auto->value,
            ],
        ]);
        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => [],
            'new_values' => ['source' => 'test'],
        ]);

        $fresh = $incident->fresh(['order', 'assignee.roles', 'supportAppointments', 'activeWaitingState']);
        $service = app()->make(ServiceCaseAssignmentService::class);
        $service->prefetchAdminReadyVisibility([$fresh]);

        $this->assertFalse($service->isVisibleInAdminReadyQueue($fresh));
        $this->assertFalse(
            app()->make(ServiceCaseAssignmentService::class)->isVisibleInAdminReadyQueue($fresh),
        );
    }

    public function test_prefetch_seeds_candidate_memo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('prefetch-memo-admin@radium.local');
        $agent = $this->createAgent('prefetch-memo-agent@radium.local');
        $this->configureShiftAdmin($admin->id);

        $hidden = $this->manualReassign(
            $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto),
            $agent,
            $admin,
        )->fresh(['order', 'assignee.roles', 'supportAppointments', 'activeWaitingState']);

        $incomplete = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto, serial: null);
        $model = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $incomplete->order->update([
            'device_model_id' => $model->id,
            'device_model' => $model->name,
            'product_name' => $model->name,
        ]);
        $visible = $this->manualReassign($incomplete->fresh(['order']), $agent, $admin);
        app(OrderSerialService::class)->assignSerialNumber($visible->order->fresh(), '7881960', $agent);
        $visible = $visible->fresh(['order', 'assignee.roles', 'supportAppointments', 'activeWaitingState']);

        $autoOnly = $this->createReadyCase($admin, assignee: $admin, origin: AssignmentOrigin::Auto)
            ->fresh(['order', 'assignee.roles', 'supportAppointments', 'activeWaitingState']);

        $service = app()->make(ServiceCaseAssignmentService::class);
        $service->prefetchAdminReadyVisibility([$hidden, $visible, $autoOnly]);

        $memo = $this->manualOwnershipReadyVisibilityMemo($service);

        $this->assertArrayHasKey((int) $hidden->id, $memo);
        $this->assertFalse($memo[(int) $hidden->id]);
        $this->assertArrayHasKey((int) $visible->id, $memo);
        $this->assertTrue($memo[(int) $visible->id]);
        $this->assertArrayNotHasKey((int) $autoOnly->id, $memo, 'Non-candidates must not be memo-seeded');

        $auditQueryCount = $this->countAuditLogQueries(function () use ($service, $hidden, $visible): void {
            $service->isVisibleInAdminReadyQueue($hidden);
            $service->isVisibleInAdminReadyQueue($visible);
        });
        $this->assertSame(0, $auditQueryCount, 'Memo hits must skip per-incident audit SQL');
    }

    /**
     * @return array<int, bool>
     */
    private function manualOwnershipReadyVisibilityMemo(ServiceCaseAssignmentService $service): array
    {
        $property = new ReflectionProperty(ServiceCaseAssignmentService::class, 'manualOwnershipReadyVisibilityMemo');

        /** @var array<int, bool> $memo */
        $memo = $property->getValue($service);

        return $memo;
    }

    private function countAuditLogQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'audit_logs'))
            ->count();
        DB::disableQueryLog();

        return $count;
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
