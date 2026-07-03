<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\SupportAppointmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class SupportAppointmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private SupportAppointmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(SupportAppointmentService::class);
    }

    public function test_book_creates_support_appointment_for_incident(): void
    {
        $incident = $this->createIncident();

        $appointment = $this->service->book($incident, [
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need help with fingerprint setup.',
        ]);

        $this->assertInstanceOf(SupportAppointment::class, $appointment);
        $this->assertDatabaseHas('support_appointments', [
            'id' => $appointment->id,
            'incident_id' => $incident->id,
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need help with fingerprint setup.',
        ]);
    }

    public function test_book_rejects_past_preferred_date(): void
    {
        $incident = $this->createIncident();

        $this->expectException(ValidationException::class);

        $this->service->book($incident, [
            'preferred_date' => now()->subDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ]);
    }

    public function test_book_rejects_invalid_phone_number(): void
    {
        $incident = $this->createIncident();

        $this->expectException(ValidationException::class);

        $this->service->book($incident, [
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => 'abc',
        ]);
    }

    public function test_book_rejects_invalid_time_slot(): void
    {
        $incident = $this->createIncident();

        $this->expectException(ValidationException::class);

        $this->service->book($incident, [
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => 'invalid-slot',
            'phone_number' => '9876543210',
        ]);
    }

    public function test_unimplemented_lifecycle_methods_throw_logic_exception(): void
    {
        $incident = $this->createIncident();
        $appointment = $this->service->book($incident, [
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ]);
        $this->expectException(LogicException::class);
        $this->service->confirm($appointment);
    }

    private function createIncident(): Incident
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-APT-'.uniqid(),
            'serial_number' => 'SN-APT-'.uniqid(),
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-APT-'.uniqid(),
            'customer_name' => 'Appointment Customer',
            'customer_email' => 'appointment@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Support appointment case',
            'description' => 'Support appointment case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }
}
