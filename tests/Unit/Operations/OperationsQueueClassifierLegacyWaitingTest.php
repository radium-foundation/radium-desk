<?php

namespace Tests\Unit\Operations;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\Operations\OperationsQueueClassifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsQueueClassifierLegacyWaitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_active_waiting_state_with_appointment_remains_waiting(): void
    {
        [$incident, $agent] = $this->createMissingSerialIncident();

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
        );

        $this->createScheduledAppointment($incident);

        $incident = $incident->fresh(['activeWaitingState', 'order', 'supportAppointments']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->isWaitingCustomer($incident));
        $this->assertSame(OperationQueue::WaitingCustomer, $classifier->classify($incident));
    }

    public function test_cleared_waiting_state_with_appointment_and_missing_serial_is_scheduled(): void
    {
        [$incident, $agent] = $this->createMissingSerialIncident();

        $waitingState = app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
        );

        $this->createScheduledAppointment($incident);

        app(IncidentWaitingStateService::class)->clear($incident->fresh(), $agent);

        $incident = $incident->fresh(['activeWaitingState', 'order', 'supportAppointments']);

        $this->assertNotNull($waitingState->fresh()->cleared_at);

        $classifier = app(OperationsQueueClassifier::class);

        $this->assertFalse($classifier->isWaitingCustomer($incident));
        $this->assertTrue($classifier->isScheduled($incident));
        $this->assertSame(OperationQueue::Scheduled, $classifier->classify($incident));
    }

    public function test_missing_serial_without_appointment_remains_waiting(): void
    {
        [$incident] = $this->createMissingSerialIncident();

        $incident = $incident->fresh(['activeWaitingState', 'order', 'supportAppointments']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->isWaitingCustomer($incident));
        $this->assertSame(OperationQueue::WaitingCustomer, $classifier->classify($incident));
    }

    /**
     * @return array{0: Incident, 1: User}
     */
    private function createMissingSerialIncident(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-LEGACY-'.uniqid(),
            'serial_number' => null,
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
            'title' => 'Legacy waiting fallback',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$incident, $agent];
    }

    private function createScheduledAppointment(Incident $incident): SupportAppointment
    {
        return SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);
    }
}
