<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\WaitingReason;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Notifications\ServiceCaseCustomerRespondedNotification;
use App\Notifications\ServiceCaseReassignedNotification;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\MissingSerial\MissingSerialAutomationService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\ServiceCaseAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WaitingStateOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'missing_serial.reminder_delay_hours' => 24,
            'waiting_states.default_follow_up_hours' => 24,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sc08197_waiting_start_sets_next_action_at_from_missing_serial_policy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:15:21', 'Asia/Kolkata'));

        [$agent, $incident] = $this->createSc08197Case(agentName: 'Jayram');

        app(IncidentWaitingStateService::class)->ensureSerialWaitingState($incident, $agent);

        $waitingState = IncidentWaitingState::query()->where('incident_id', $incident->id)->first();

        $this->assertNotNull($waitingState);
        $this->assertSame(WaitingReason::SerialNumber, $waitingState->waiting_reason);
        $this->assertNotNull($waitingState->next_action_at);
        $this->assertTrue($waitingState->next_action_at->equalTo(
            Carbon::parse('2026-07-10 10:15:21', 'Asia/Kolkata'),
        ));

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'event' => 'service_case.customer_waiting_started',
        ]);
    }

    public function test_sc08197_assigned_waiting_case_appears_in_my_work_before_follow_up_is_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:20:00', 'Asia/Kolkata'));

        [$agent, $incident] = $this->createSc08197Case();

        app(IncidentWaitingStateService::class)->ensureSerialWaitingState($incident, $agent);

        $freshIncident = $incident->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->isWaitingCustomer($freshIncident));
        $this->assertFalse($classifier->isWaitingFollowUpDue($freshIncident));
        $this->assertTrue($classifier->matchesMyWork($freshIncident, $agent));
        $this->assertSame(1, DashboardSnapshot::load()->incidentsForQueue('my_work', $agent)->count());
    }

    public function test_sc08197_serial_received_wakes_owner_and_returns_case_to_my_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:01:19', 'Asia/Kolkata'));

        Notification::fake();

        [$agent, $incident] = $this->createSc08197Case();

        app(IncidentWaitingStateService::class)->ensureSerialWaitingState($incident, $agent);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => '2026-07-09',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '8121244337',
            'status' => 'scheduled',
        ]);

        $incident->order->update([
            'serial_number' => '9620545',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $agent->id,
        ]);

        app(MissingSerialAutomationService::class)->markCompletedIfApplicable(
            $incident->order->fresh(),
            'serial_resolved',
        );

        $freshIncident = $incident->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertFalse($classifier->isWaitingCustomer($freshIncident));
        $this->assertSame('scheduled', $classifier->classify($freshIncident)->value);
        $this->assertTrue($classifier->matchesMyWork($freshIncident, $agent));

        Notification::assertSentTo($agent, ServiceCaseCustomerRespondedNotification::class);
    }

    public function test_sc08197_todays_scheduled_appointment_appears_in_my_work_for_assignee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 11:48:00', 'Asia/Kolkata'));

        [$agent, $incident] = $this->createSc08197Case(assignee: null);

        $admin = User::factory()->create(['name' => 'Avinash']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incident->update(['assigned_to_user_id' => $admin->id]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => '2026-07-09',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '8121244337',
            'status' => 'scheduled',
        ]);

        $freshIncident = $incident->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->hasTodaysScheduledAppointment($freshIncident));
        $this->assertTrue($classifier->matchesMyWork($freshIncident, $admin));
        $this->assertSame(1, DashboardSnapshot::load()->incidentsForQueue('my_work', $admin)->count());
    }

    public function test_no_op_reassignment_does_not_create_audit_or_notification(): void
    {
        Notification::fake();

        $actor = User::factory()->create(['name' => 'Demo Agent']);
        $actor->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $assignee = User::factory()->create(['name' => 'Jayram']);
        $assignee->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createSc08197Case($assignee)[1];
        $incident->update(['assigned_to_user_id' => $assignee->id]);

        $auditCountBefore = AuditLog::query()
            ->where('auditable_type', Incident::class)
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.reassigned')
            ->count();

        app(ServiceCaseAssignmentService::class)->reassign(
            incident: $incident->fresh(['assignee']),
            assignee: $assignee,
            actor: $actor,
        );

        $this->assertSame($assignee->id, $incident->fresh()->assigned_to_user_id);
        $this->assertSame(
            $auditCountBefore,
            AuditLog::query()
                ->where('auditable_type', Incident::class)
                ->where('auditable_id', $incident->id)
                ->where('event', 'service_case.reassigned')
                ->count(),
        );

        Notification::assertNotSentTo($assignee, ServiceCaseReassignedNotification::class);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createSc08197Case(?User $assignee = null, string $agentName = 'Jayram'): array
    {
        $agent = $assignee ?? User::factory()->create(['name' => $agentName]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3445094',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Allurijagadeshwar',
            'customer_phone' => '8121244337',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC08197',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Cashfree payment — RD3445094',
            'description' => 'SC08197 investigation fixture.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments'])];
    }
}
