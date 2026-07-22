<?php

namespace Tests\Feature\Repair;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Repairs\Appointments\ClosedAppointmentWorkflowItemHandler;
use App\Services\ServiceCaseStatusService;
use App\Services\SettingService;
use App\Support\Repair\Enums\RepairBatchStatus;
use App\Support\Repair\Models\SystemRepairBatch;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepairClosedAppointmentWorkflowCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'smart_assignment.enabled' => true,
            'smart_assignment.deferred.enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'repair.require_global_lock' => false,
        ]);

        Storage::fake('local');

        $systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'name' => 'Ira',
        ]);
        $systemUser->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        Carbon::setTestNow(Carbon::parse('2026-07-22 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_previews_full_repair_without_mutating_domain(): void
    {
        $admin = $this->createAdmin('Shift Admin');
        $this->createSupportAgent('Ready Agent', TeamAvailabilityStatus::Available);
        [$incident, $appointment] = $this->createClosedCaseWithScheduledAppointment(
            assignee: $admin,
            preferredDate: '2026-07-25',
        );

        $this->artisan('support-appointments:repair-closed-workflow', [
            '--force' => true,
            '--summary-only' => true,
        ])->assertSuccessful();

        $incident->refresh();
        $appointment->refresh();

        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertSame(SupportAppointmentStatus::Scheduled, $appointment->status);
        $this->assertDatabaseHas('system_repair_batches', [
            'repair_key' => 'appointments.closed_workflow',
            'status' => RepairBatchStatus::Previewed->value,
        ]);
        $this->assertDatabaseHas('system_repair_items', [
            'subject_id' => $incident->id,
            'action' => 'full',
            'outcome' => 'would_repair',
        ]);
    }

    public function test_execute_reopens_and_smart_assigns_future_appointment(): void
    {
        $admin = $this->createAdmin('Shift Admin');
        $agent = $this->createSupportAgent('Ready Agent', TeamAvailabilityStatus::Available);
        [$incident] = $this->createClosedCaseWithScheduledAppointment(
            assignee: $admin,
            preferredDate: '2026-07-25',
        );

        $this->artisan('support-appointments:repair-closed-workflow', [
            '--execute' => true,
            '--force' => true,
            '--summary-only' => true,
            '--run-deferred' => '0',
        ])->assertSuccessful();

        $incident->refresh();
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => ClosedAppointmentWorkflowItemHandler::EVENT_REPAIRED,
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.appointment_booking_reopened',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_cleanup_completes_stale_appointment_and_leaves_case_closed(): void
    {
        $admin = $this->createAdmin('Shift Admin');
        app(SettingService::class)->set('assignment.day_shift_admin_user_id', $admin->id);
        app(SettingService::class)->set('assignment.night_shift_admin_user_id', $admin->id);

        [$incident, $appointment] = $this->createClosedCaseWithScheduledAppointment(
            assignee: $admin,
            preferredDate: '2026-07-10',
        );

        $this->artisan('support-appointments:repair-closed-workflow', [
            '--execute' => true,
            '--force' => true,
            '--summary-only' => true,
            '--include-past' => true,
            '--mode' => 'cleanup',
            '--run-deferred' => '0',
        ])->assertSuccessful();

        $incident->refresh();
        $appointment->refresh();

        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertSame(SupportAppointmentStatus::Completed, $appointment->status);
        $this->assertNull($incident->assigned_to_user_id);
    }

    public function test_superseded_by_newer_case_uses_cleanup(): void
    {
        $admin = $this->createAdmin('Shift Admin');
        [$incident, $appointment] = $this->createClosedCaseWithScheduledAppointment(
            assignee: $admin,
            preferredDate: '2026-07-25',
        );

        Incident::query()->create([
            'order_id' => $incident->order_id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Newer case',
            'description' => 'Newer case',
            'status' => IncidentStatus::Resolved,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        $this->artisan('support-appointments:repair-closed-workflow', [
            '--execute' => true,
            '--force' => true,
            '--summary-only' => true,
            '--run-deferred' => '0',
        ])->assertSuccessful();

        $incident->refresh();
        $appointment->refresh();

        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertSame(SupportAppointmentStatus::Completed, $appointment->status);
        $this->assertDatabaseHas('system_repair_items', [
            'subject_id' => $incident->id,
            'action' => 'cleanup',
            'category' => 'superseded_by_newer_case',
            'outcome' => 'cleaned_up',
        ]);
    }

    public function test_execute_is_idempotent_on_second_run(): void
    {
        $admin = $this->createAdmin('Shift Admin');
        $this->createSupportAgent('Ready Agent', TeamAvailabilityStatus::Available);
        [$incident] = $this->createClosedCaseWithScheduledAppointment(
            assignee: $admin,
            preferredDate: '2026-07-25',
        );

        $this->artisan('support-appointments:repair-closed-workflow', [
            '--execute' => true,
            '--force' => true,
            '--summary-only' => true,
            '--run-deferred' => '0',
        ])->assertSuccessful();

        $assigneeId = $incident->fresh()->assigned_to_user_id;

        $this->artisan('support-appointments:repair-closed-workflow', [
            '--execute' => true,
            '--force' => true,
            '--summary-only' => true,
            '--run-deferred' => '0',
        ])->assertSuccessful();

        $this->assertSame($assigneeId, $incident->fresh()->assigned_to_user_id);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_rollback_restores_closed_state(): void
    {
        $admin = $this->createAdmin('Shift Admin');
        $this->createSupportAgent('Ready Agent', TeamAvailabilityStatus::Available);
        [$incident, $appointment] = $this->createClosedCaseWithScheduledAppointment(
            assignee: $admin,
            preferredDate: '2026-07-25',
        );

        $this->artisan('support-appointments:repair-closed-workflow', [
            '--execute' => true,
            '--force' => true,
            '--summary-only' => true,
            '--run-deferred' => '0',
        ])->assertSuccessful();

        $batch = SystemRepairBatch::query()->latest('id')->first();
        $this->assertNotNull($batch);

        $this->artisan('support-appointments:repair-closed-workflow', [
            '--rollback' => true,
            '--execute' => true,
            '--batch' => $batch->uuid,
            '--force' => true,
            '--summary-only' => true,
        ])->assertSuccessful();

        $incident->refresh();
        $appointment->refresh();

        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertSame($admin->id, $incident->assigned_to_user_id);
        $this->assertSame(SupportAppointmentStatus::Scheduled, $appointment->status);
    }

    /**
     * @return array{0: Incident, 1: SupportAppointment}
     */
    private function createClosedCaseWithScheduledAppointment(User $assignee, string $preferredDate): array
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-REPAIR-'.uniqid(),
            'serial_number' => 'SN-REPAIR',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Repair Customer',
            'customer_email' => 'repair@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Repair closed workflow case',
            'description' => 'Repair closed workflow case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
        ]);

        app(ServiceCaseStatusService::class)->updateStatus(
            incident: $incident,
            status: IncidentStatus::Closed,
            actor: $assignee,
        );

        $appointment = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => $preferredDate,
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        return [$incident->fresh(['assignee', 'order']), $appointment->fresh()];
    }

    private function createAdmin(string $name): User
    {
        $admin = User::factory()->create(['name' => $name]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin->fresh();
    }

    private function createSupportAgent(string $name, TeamAvailabilityStatus $status): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);

        if ($status !== TeamAvailabilityStatus::Offline) {
            app(PresenceEngineService::class)->startSession($user);
        }

        return $user->fresh();
    }
}
