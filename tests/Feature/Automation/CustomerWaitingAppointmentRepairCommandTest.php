<?php

namespace Tests\Feature\Automation;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Automation\CustomerWaitingAppointmentRepairService;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\Operations\OperationsQueueClassifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerWaitingAppointmentRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_appointment_with_waiting_clears_waiting_state(): void
    {
        $this->seed(RolePermissionSeeder::class);

        [$agent, $incident, $waitingState] = $this->createScenario();

        $classifier = app(OperationsQueueClassifier::class);
        $this->assertTrue($classifier->isWaitingCustomer($incident->fresh()));

        $this->artisan('customer-waiting:repair-appointments')
            ->expectsOutputToContain('appointments_found: 1')
            ->expectsOutputToContain('waiting_states_cleared: 1')
            ->assertSuccessful();

        $waitingState = $waitingState->fresh();
        $incident = $incident->fresh(['supportAppointments']);

        $this->assertNotNull($waitingState->cleared_at);
        $this->assertFalse($classifier->isWaitingCustomer($incident));
        $this->assertTrue($classifier->isScheduled($incident));
        $this->assertDatabaseHas('audit_logs', [
            'event' => CustomerWaitingAppointmentRepairService::EVENT_WAITING_CLEARED,
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_cancelled_appointment_is_skipped(): void
    {
        $this->seed(RolePermissionSeeder::class);

        [, $incident, $waitingState] = $this->createScenario(
            appointmentStatus: SupportAppointmentStatus::Cancelled,
        );

        $this->artisan('customer-waiting:repair-appointments')
            ->expectsOutputToContain('appointments_found: 0')
            ->expectsOutputToContain('waiting_states_cleared: 0')
            ->expectsOutputToContain('skipped: 1')
            ->assertSuccessful();

        $this->assertNull($waitingState->fresh()->cleared_at);
        $this->assertTrue(app(OperationsQueueClassifier::class)->isWaitingCustomer($incident->fresh()));
    }

    public function test_closed_incident_is_skipped(): void
    {
        $this->seed(RolePermissionSeeder::class);

        [, $incident, $waitingState] = $this->createScenario(
            incidentStatus: IncidentStatus::Closed,
        );

        $this->artisan('customer-waiting:repair-appointments')
            ->expectsOutputToContain('appointments_found: 0')
            ->expectsOutputToContain('waiting_states_cleared: 0')
            ->expectsOutputToContain('skipped: 1')
            ->assertSuccessful();

        $this->assertNull($waitingState->fresh()->cleared_at);
    }

    public function test_dry_run_makes_no_writes(): void
    {
        $this->seed(RolePermissionSeeder::class);

        [, , $waitingState] = $this->createScenario();

        $this->artisan('customer-waiting:repair-appointments', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('appointments_found: 1')
            ->expectsOutputToContain('waiting_states_cleared: 0')
            ->assertSuccessful();

        $this->assertNull($waitingState->fresh()->cleared_at);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => CustomerWaitingAppointmentRepairService::EVENT_WAITING_CLEARED,
        ]);
    }

    public function test_second_run_produces_zero_changes(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->createScenario();

        $this->artisan('customer-waiting:repair-appointments')->assertSuccessful();

        $this->artisan('customer-waiting:repair-appointments')
            ->expectsOutputToContain('appointments_found: 0')
            ->expectsOutputToContain('waiting_states_cleared: 0')
            ->assertSuccessful();
    }

    /**
     * @return array{0: User, 1: Incident, 2: IncidentWaitingState}
     */
    private function createScenario(
        SupportAppointmentStatus $appointmentStatus = SupportAppointmentStatus::Scheduled,
        IncidentStatus $incidentStatus = IncidentStatus::Open,
    ): array {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-APPT-'.uniqid(),
            'serial_number' => 'SN123456789',
            'serial_entered_at' => now(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Appointment waiting repair',
            'description' => 'Waiting with appointment.',
            'status' => $incidentStatus,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $waitingState = app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
        );

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => $appointmentStatus,
        ]);

        return [$agent, $incident, $waitingState];
    }
}
