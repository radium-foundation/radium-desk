<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseStatusService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCaseStatusSupportAppointmentSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_closing_incident_completes_scheduled_support_appointment(): void
    {
        [$admin, $incident, $appointment] = $this->createFixtureWithScheduledAppointment();

        app(ServiceCaseStatusService::class)->updateStatus(
            $incident->fresh(),
            IncidentStatus::Closed,
            $admin,
        );

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertDatabaseHas('support_appointments', [
            'id' => $appointment->id,
            'incident_id' => $incident->id,
            'status' => SupportAppointmentStatus::Completed->value,
        ]);
        $this->assertFalse($incident->fresh()->hasActiveSupportAppointment());
    }

    public function test_closed_incident_does_not_expose_active_scheduled_support_card(): void
    {
        [$admin, $incident, $appointment] = $this->createFixtureWithScheduledAppointment();

        $incident->update(['status' => IncidentStatus::Closed]);

        SupportAppointment::query()
            ->whereKey($appointment->id)
            ->update(['status' => SupportAppointmentStatus::Scheduled]);

        $response = $this->actingAs($admin)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertDontSee('data-customer-360-section="support-appointments"', false);
        $response->assertDontSee('Scheduled Support', false);
    }

    public function test_closed_incident_hides_scheduled_support_card_after_status_sync(): void
    {
        [$admin, $incident] = $this->createFixtureWithScheduledAppointment();

        app(ServiceCaseStatusService::class)->updateStatus(
            $incident->fresh(),
            IncidentStatus::Closed,
            $admin,
        );

        $response = $this->actingAs($admin)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertDontSee('data-customer-360-section="support-appointments"', false);
        $response->assertDontSee('Scheduled Support', false);
    }

    public function test_appointment_history_remains_available_after_incident_close(): void
    {
        [$admin, $incident, $appointment] = $this->createFixtureWithScheduledAppointment();

        app(ServiceCaseStatusService::class)->updateStatus(
            $incident->fresh(),
            IncidentStatus::Closed,
            $admin,
        );

        $this->assertDatabaseHas('support_appointments', [
            'id' => $appointment->id,
            'incident_id' => $incident->id,
        ]);

        $events = app(Customer360TimelineService::class)
            ->forIncident($incident->fresh())
            ->events();
        $appointmentEvents = $events->filter(
            fn ($event) => $event->type === TimelineEventType::Appointment,
        );

        $this->assertTrue($appointmentEvents->isNotEmpty());
    }

    /**
     * @return array{0: User, 1: Incident, 2: SupportAppointment}
     */
    private function createFixtureWithScheduledAppointment(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-APT-SYNC-'.uniqid(),
            'serial_number' => 'SN-APT-SYNC-'.uniqid(),
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-APT-SYNC-'.uniqid(),
            'customer_name' => 'Appointment Sync Customer',
            'customer_email' => 'sync@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Support appointment sync case',
            'description' => 'Support appointment sync case.',
            'status' => IncidentStatus::Open,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        $appointment = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
            'additional_notes' => 'Fingerprint setup help.',
        ]);

        return [$admin, $incident, $appointment];
    }
}
