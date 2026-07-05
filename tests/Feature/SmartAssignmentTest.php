<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\SmartAssignmentService;
use App\Services\Operations\SupportAppointmentSmartAssignmentService;
use App\Services\SupportAppointmentService;
use App\Services\SupportScheduleAvailabilityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SmartAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['smart_assignment.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_available_team_member_gets_assigned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $availableAgent = $this->createSupportAgent('Avinash Jha', TeamAvailabilityStatus::Available);
        $incident = $this->createUnassignedIncident();

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($availableAgent->id, $incident->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_id' => $incident->id,
        ]);

        $auditLog = AuditLog::query()
            ->where('event', 'service_case.assigned')
            ->where('auditable_id', $incident->id)
            ->first();

        $this->assertSame('smart', $auditLog?->new_values['assignment_method'] ?? null);
        $this->assertContains('Available', $auditLog?->new_values['assignment_reason']['factors'] ?? []);
    }

    public function test_on_leave_user_is_skipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $this->createSupportAgent('On Leave Agent', TeamAvailabilityStatus::OnLeave);
        $incident = $this->createUnassignedIncident();

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.smart_assignment_unassigned',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_lower_workload_user_is_preferred(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $busyAgent = $this->createSupportAgent('Busy Agent', TeamAvailabilityStatus::Available);
        $lightAgent = $this->createSupportAgent('Light Agent', TeamAvailabilityStatus::Available);

        $this->createAssignedIncident($busyAgent, 'RD-WORK-1');
        $this->createAssignedIncident($busyAgent, 'RD-WORK-2');

        $incident = $this->createUnassignedIncident();
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($lightAgent->id, $incident->assigned_to_user_id);
    }

    public function test_busy_user_is_used_only_when_needed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $availableAgent = $this->createSupportAgent('Available Agent', TeamAvailabilityStatus::Available);
        $busyAgent = $this->createSupportAgent('Busy Agent', TeamAvailabilityStatus::Busy);

        $incident = $this->createUnassignedIncident();
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($availableAgent->id, $incident->assigned_to_user_id);
        $this->assertNotSame($busyAgent->id, $incident->assigned_to_user_id);

        $availableAgent->update(['availability_status' => TeamAvailabilityStatus::Offline]);
        $incidentTwo = $this->createUnassignedIncident('RD-BUSY-ONLY');
        $this->bookAppointment($incidentTwo);

        $incidentTwo->refresh();
        $this->assertSame($busyAgent->id, $incidentTwo->assigned_to_user_id);
    }

    public function test_appointment_booking_triggers_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgent('Booking Agent', TeamAvailabilityStatus::Available);
        $incident = $this->createUnassignedIncident();

        Event::fake([
            \App\Events\Operations\SupportAppointmentSmartAssigned::class,
        ]);

        $appointment = app(SupportAppointmentService::class)->book($incident, [
            'preferred_date' => '2026-07-07',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ]);

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertNotNull($appointment->id);

        Event::assertDispatched(\App\Events\Operations\SupportAppointmentSmartAssigned::class);
    }

    public function test_manual_assignment_is_preserved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $manualAssignee = $this->createSupportAgent('Manual Owner', TeamAvailabilityStatus::Available);
        $this->createSupportAgent('Other Agent', TeamAvailabilityStatus::Available);

        $incident = $this->createUnassignedIncident();
        $incident->update(['assigned_to_user_id' => $manualAssignee->id]);

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($manualAssignee->id, $incident->assigned_to_user_id);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->count());
    }

    public function test_no_available_users_keeps_case_unassigned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createSupportAgent('Offline Agent', TeamAvailabilityStatus::Offline);
        $incident = $this->createUnassignedIncident();

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertNull($incident->assigned_to_user_id);

        $classifier = app(OperationsQueueClassifier::class);
        $incident = $incident->fresh(['supportAppointments', 'order', 'activeWaitingState']);
        $this->assertTrue($classifier->isScheduled($incident));
        $this->assertSame('scheduled', $classifier->classify($incident)->value);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.smart_assignment_unassigned',
            'auditable_id' => $incident->id,
        ]);

        Notification::assertSentTo($admin, \App\Notifications\SmartAssignmentUnassignedNotification::class);
    }

    public function test_assigned_scheduled_case_appears_in_my_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgent('My Work Agent', TeamAvailabilityStatus::Available);
        $incident = $this->createUnassignedIncident();
        $this->bookAppointment($incident);

        $incident = $incident->fresh(['supportAppointments', 'order', 'activeWaitingState', 'assignee']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->matchesQueue($incident, 'my_work', $agent));
        $this->assertSame(1, DashboardSnapshot::load()->incidentsForQueue('my_work', $agent)->count());
    }

    public function test_smart_assignment_service_prefers_recently_active_when_workloads_match(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $idleAgent = $this->createSupportAgent('Idle Agent', TeamAvailabilityStatus::Available);
        $activeAgent = $this->createSupportAgent('Active Agent', TeamAvailabilityStatus::Available);
        $activeAgent->update(['last_case_action_at' => now()->subMinutes(30)]);

        $result = app(SmartAssignmentService::class)->resolveBestAssignee();

        $this->assertTrue($result->isAssigned());
        $this->assertSame($activeAgent->id, $result->assignee?->id);
        $this->assertNotSame($idleAgent->id, $result->assignee?->id);
    }

    private function createSupportAgent(string $name, TeamAvailabilityStatus $status): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function createUnassignedIncident(string $orderId = 'RD-SMART-1'): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Smart Assignment Customer',
            'customer_email' => 'smart@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Smart assignment case',
            'description' => 'Smart assignment case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => null,
        ]);
    }

    private function createAssignedIncident(User $assignee, string $orderId): Incident
    {
        $incident = $this->createUnassignedIncident($orderId);
        $incident->update(['assigned_to_user_id' => $assignee->id]);

        return $incident->fresh();
    }

    private function bookAppointment(Incident $incident): SupportAppointment
    {
        return app(SupportAppointmentService::class)->book($incident, [
            'preferred_date' => '2026-07-07',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need remote support.',
        ]);
    }
}
