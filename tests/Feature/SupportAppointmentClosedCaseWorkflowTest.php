<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Enums\SupportAppointmentStatus;
use App\Services\IncidentReferenceService;
use App\Services\Operations\DeferredSmartAssignmentService;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\ServiceCaseStatusService;
use App\Services\SupportAppointmentBookingWorkflowService;
use App\Services\SupportAppointmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SupportAppointmentClosedCaseWorkflowTest extends TestCase
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
        ]);

        $systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'name' => 'Ira',
        ]);
        $systemUser->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_closed_case_is_reopened_when_appointment_is_booked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgent('Ready Agent', TeamAvailabilityStatus::Available);
        $admin = $this->createAdmin('Shift Admin');
        $incident = $this->createClosedIncidentOwnedBy($admin);

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => SupportAppointmentBookingWorkflowService::EVENT_APPOINTMENT_BOOKING_REOPENED,
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.status_changed',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_closed_case_with_no_engineer_clears_admin_and_marks_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $admin = $this->createAdmin('Shift Admin');
        $incident = $this->createClosedIncidentOwnedBy($admin);

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertTrue($incident->pending_smart_assignment);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.pending_smart_assignment',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_deferred_assignment_assigns_reopened_pending_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        Event::fake([SupportAppointmentSmartAssigned::class]);

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $admin = $this->createAdmin('Shift Admin');
        $incident = $this->createClosedIncidentOwnedBy($admin);
        $this->bookAppointment($incident);

        config(['smart_assignment.deferred.enabled' => false]);
        $agent = $this->createSupportAgent('Deferred Agent', TeamAvailabilityStatus::Available);
        config(['smart_assignment.deferred.enabled' => true]);

        $assigned = app(DeferredSmartAssignmentService::class)->processPendingBatch();

        $incident->refresh();
        $this->assertSame(1, $assigned);
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertFalse($incident->pending_smart_assignment);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.deferred_smart_assignment',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_timeline_includes_reopen_and_pending_messages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $admin = $this->createAdmin('Shift Admin');
        $incident = $this->createClosedIncidentOwnedBy($admin);

        $this->bookAppointment($incident);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());
        $titles = $timeline->map(fn ($entry) => $entry->title)->all();
        $bodies = $timeline->map(fn ($entry) => $entry->body)->filter()->values()->all();

        $this->assertContains('Case reopened automatically after Tech Support appointment booking.', $titles);
        $this->assertContains('Waiting for available support engineer.', $bodies);
    }

    public function test_deferred_assignment_skips_legacy_closed_pending_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('Shift Admin');
        $incident = $this->createClosedIncidentOwnedBy($admin);
        $incident->update([
            'pending_smart_assignment' => true,
            'assigned_to_user_id' => $admin->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => '2026-07-10',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $assigned = app(DeferredSmartAssignmentService::class)->processPendingBatch();

        $this->assertSame(0, $assigned);
        $this->assertFalse($incident->fresh()->pending_smart_assignment);
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

    private function createClosedIncidentOwnedBy(User $assignee): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-CLOSED-'.uniqid(),
            'serial_number' => 'SN-CLOSED',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Closed Case Customer',
            'customer_email' => 'closed@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed appointment workflow case',
            'description' => 'Closed appointment workflow case.',
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

        return $incident->fresh(['assignee', 'order']);
    }

    private function bookAppointment(Incident $incident): void
    {
        app(SupportAppointmentService::class)->book($incident, [
            'preferred_date' => '2026-07-07',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ]);
    }
}
