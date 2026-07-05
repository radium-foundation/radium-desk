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
use App\Services\SupportScheduleAvailabilityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
            'preferred_date' => app(SupportScheduleAvailabilityService::class)->nextBookableDate()->toDateString(),
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
        $agent = User::factory()->create([
            'first_name' => 'Shipra',
            'last_name' => 'Kumari',
            'name' => 'Shipra Kumari',
        ]);
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
        $response->assertSee('Assigned To', false);
        $response->assertSee('Shipra', false);
        $response->assertSee('Afternoon (12 PM – 3 PM)', false);
        $response->assertDontSee('9123456789', false);
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

    public function test_booking_form_shows_preferred_placeholders_and_calendar_icon(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $bookingUrl = app(SupportAppointmentUrlService::class)->bookingUrl($incident);

        $this->get($bookingUrl)
            ->assertOk()
            ->assertSee('Select preferred time slot', false)
            ->assertSee('placeholder="Select preferred date"', false)
            ->assertSee('bi-calendar3', false);
    }

    public function test_booking_rejects_sunday_preferred_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 10:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $storeUrl = URL::temporarySignedRoute(
            'support-appointments.store',
            now()->addDays(30),
            ['incident' => $incident->id],
        );

        $this->post($storeUrl, [
            'preferred_date' => '2026-07-05',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ])->assertSessionHasErrors('preferred_date');

        $this->assertDatabaseCount('support_appointments', 0);
    }

    public function test_booking_rejects_same_day_slot_after_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $storeUrl = URL::temporarySignedRoute(
            'support-appointments.store',
            now()->addDays(30),
            ['incident' => $incident->id],
        );

        $this->post($storeUrl, [
            'preferred_date' => '2026-07-06',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ])->assertSessionHasErrors('preferred_time_slot');

        $this->assertDatabaseCount('support_appointments', 0);
    }

    public function test_duplicate_submit_returns_existing_confirmation_without_second_appointment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $storeUrl = URL::temporarySignedRoute(
            'support-appointments.store',
            now()->addDays(30),
            ['incident' => $incident->id],
        );

        $payload = [
            'preferred_date' => '2026-07-07',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need help with fingerprint setup.',
        ];

        $firstResponse = $this->post($storeUrl, $payload);
        $firstResponse->assertRedirect();

        $appointment = SupportAppointment::query()->first();
        $this->assertNotNull($appointment);

        $secondResponse = $this->post($storeUrl, $payload);
        $secondResponse->assertRedirect(
            URL::temporarySignedRoute(
                'support-appointments.confirmation',
                now()->addHour(),
                [
                    'incident' => $incident->id,
                    'appointment' => $appointment->id,
                ],
            ),
        );

        $this->assertDatabaseCount('support_appointments', 1);
    }

    public function test_confirmation_page_shows_back_to_whatsapp_action(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $storeUrl = URL::temporarySignedRoute(
            'support-appointments.store',
            now()->addDays(30),
            ['incident' => $incident->id],
        );

        $response = $this->post($storeUrl, [
            'preferred_date' => '2026-07-07',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ]);

        $this->followingRedirects()->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Back to WhatsApp', false)
            ->assertSee('data-support-appointment-close', false);
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
