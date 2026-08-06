<?php

namespace Tests\Feature;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseStatusService;
use App\Services\SupportAppointmentBookingWorkflowService;
use App\Services\SupportAppointmentConfirmationNotificationService;
use App\Services\SupportAppointmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TechSupportAppointmentOwnershipWorkflowTest extends TestCase
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

    public function test_open_case_booking_assigns_support_engineer_and_clears_ready_queue_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('Ready Queue Admin');
        $agent = $this->createSupportAgent('Support Engineer', TeamAvailabilityStatus::Available);
        $incident = $this->createOpenIncidentOwnedBy($admin);

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertNotSame($admin->id, $incident->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::AppointmentSmartAssignment, $incident->assignment_origin);
        $this->assertFalse($incident->pending_smart_assignment);

        $assignAudit = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.reassigned')
            ->latest('id')
            ->first();

        $this->assertNotNull($assignAudit);
        $this->assertSame(
            AssignmentOrigin::AppointmentSmartAssignment->value,
            $assignAudit->new_values['assignment_origin'] ?? null,
        );
        $this->assertSame(
            SupportAppointmentBookingWorkflowService::ASSIGNMENT_REASON,
            $assignAudit->new_values['reason'] ?? null,
        );
    }

    public function test_closed_case_booking_reopens_then_assigns_support_engineer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('Ready Queue Admin');
        $agent = $this->createSupportAgent('Support Engineer', TeamAvailabilityStatus::Available);
        $incident = $this->createClosedIncidentOwnedBy($admin);

        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::AppointmentSmartAssignment, $incident->assignment_origin);

        $bookedId = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', SupportAppointmentBookingWorkflowService::EVENT_APPOINTMENT_BOOKED)
            ->value('id');
        $reopenId = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', SupportAppointmentBookingWorkflowService::EVENT_APPOINTMENT_BOOKING_REOPENED)
            ->value('id');
        $assignId = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->whereIn('event', ['service_case.assigned', 'service_case.reassigned'])
            ->where('new_values->assignment_origin', AssignmentOrigin::AppointmentSmartAssignment->value)
            ->value('id');

        $this->assertNotNull($bookedId);
        $this->assertNotNull($reopenId);
        $this->assertNotNull($assignId);
        $this->assertTrue($bookedId < $reopenId);
        $this->assertTrue($reopenId < $assignId);
    }

    public function test_no_engineer_moves_to_support_queue_pending_without_ready_queue_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('Ready Queue Admin');
        $this->createSupportAgent('Offline Engineer', TeamAvailabilityStatus::Offline);
        $incident = $this->createOpenIncidentOwnedBy($admin);

        $this->bookAppointment($incident);

        $incident = $incident->fresh(['supportAppointments', 'order', 'assignee']);
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertTrue($incident->pending_smart_assignment);
        $this->assertSame(AssignmentOrigin::AppointmentSmartAssignment, $incident->assignment_origin);

        $classifier = app(OperationsQueueClassifier::class);
        $this->assertTrue($classifier->isScheduled($incident));
        $this->assertSame('scheduled', $classifier->classify($incident)->value);
        $this->assertSame(
            1,
            DashboardSnapshot::load()->incidentsForQueue('scheduled')->count(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.pending_smart_assignment',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_confirmation_notification_runs_only_after_ownership_transition(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('Ready Queue Admin');
        $agent = $this->createSupportAgent('Support Engineer', TeamAvailabilityStatus::Available);
        $incident = $this->createOpenIncidentOwnedBy($admin);

        $this->mock(SupportAppointmentConfirmationNotificationService::class, function ($mock) use ($incident, $admin, $agent): void {
            $mock->shouldReceive('send')
                ->once()
                ->andReturnUsing(function () use ($incident, $admin, $agent): void {
                    $fresh = $incident->fresh();
                    $this->assertNotSame($admin->id, $fresh?->assigned_to_user_id);
                    $this->assertSame($agent->id, $fresh?->assigned_to_user_id);
                    $this->assertSame(
                        AssignmentOrigin::AppointmentSmartAssignment,
                        $fresh?->assignment_origin,
                    );
                });
        });

        $this->bookAppointment($incident);
    }

    public function test_appointment_appears_under_support_engineer_my_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin('Ready Queue Admin');
        $agent = $this->createSupportAgent('Support Engineer', TeamAvailabilityStatus::Available);
        $incident = $this->createOpenIncidentOwnedBy($admin);

        $this->bookAppointment($incident);

        $incident = $incident->fresh(['supportAppointments', 'order', 'assignee', 'activeWaitingState']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->matchesQueue($incident, 'my_work', $agent));
        $this->assertFalse($classifier->matchesQueue($incident, 'my_work', $admin));
        $this->assertSame(1, DashboardSnapshot::load()->incidentsForQueue('my_work', $agent)->count());
        $this->assertSame(0, DashboardSnapshot::load()->incidentsForQueue('my_work', $admin)->count());
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

    private function createOpenIncidentOwnedBy(User $assignee): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-OWN-'.uniqid(),
            'serial_number' => 'SN-OWN',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Ownership Customer',
            'customer_email' => 'ownership@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Tech support ownership case',
            'description' => 'Tech support ownership case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
            'assignment_origin' => AssignmentOrigin::Auto,
        ]);
    }

    private function createClosedIncidentOwnedBy(User $assignee): Incident
    {
        $incident = $this->createOpenIncidentOwnedBy($assignee);

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
            'preferred_date' => '2026-08-07',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ]);
    }
}
