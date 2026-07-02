<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\SupportAppointmentUrlService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SupportAppointmentBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_can_book_support_appointment_via_signed_url(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $bookingUrl = app(SupportAppointmentUrlService::class)->bookingUrl($incident);

        $this->get($bookingUrl)
            ->assertOk()
            ->assertSee('Schedule Technical Support', false)
            ->assertSee('Preferred date', false)
            ->assertSee('Preferred time slot', false);

        $storeUrl = URL::temporarySignedRoute(
            'support-appointments.store',
            now()->addDays(30),
            ['incident' => $incident->id],
        );

        $response = $this->post($storeUrl, [
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need help with fingerprint setup.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('support_appointments', [
            'incident_id' => $incident->id,
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need help with fingerprint setup.',
        ]);

        $appointment = SupportAppointment::query()->first();

        $response->assertRedirect(
            URL::temporarySignedRoute(
                'support-appointments.confirmation',
                now()->addHour(),
                [
                    'incident' => $incident->id,
                    'appointment' => $appointment->id,
                ],
            ),
        );

        $this->followingRedirects()->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Your appointment is confirmed', false)
            ->assertSee('Morning (9 AM – 12 PM)', false)
            ->assertSee('9876543210', false)
            ->assertSee('Need help with fingerprint setup.', false);
    }

    public function test_booking_requires_valid_signed_url(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->get(route('support-appointments.create', $incident))
            ->assertForbidden();
    }

    public function test_customer_360_displays_scheduled_support_appointment(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Afternoon,
            'phone_number' => '9123456789',
            'additional_notes' => 'Device not connecting to RD Service.',
        ]);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('data-customer-360-section="support-appointments"', false);
        $response->assertSee('Scheduled Support', false);
        $response->assertSee('Afternoon (12 PM – 4 PM)', false);
        $response->assertSee('9123456789', false);
        $response->assertSee('Device not connecting to RD Service.', false);
    }

    public function test_customer_360_hides_support_appointments_section_when_none_exist(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertDontSee('data-customer-360-section="support-appointments"', false);
        $response->assertDontSee('Scheduled Support', false);
    }

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $agent): array
    {
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

        $incident = Incident::query()->create([
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

        return [$incident, $order];
    }
}
