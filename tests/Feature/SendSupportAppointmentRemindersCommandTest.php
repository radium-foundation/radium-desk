<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SendSupportAppointmentRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'team_telegram.enabled' => true,
            'team_telegram.appointment_reminders.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_outputs_diagnostic_funnel(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 16:42:00', 'Asia/Kolkata'));

        $agent = User::factory()->create([
            'is_active' => true,
            'telegram_chat_id' => '123456',
            'telegram_notifications_enabled' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $futureIncident = $this->createIncidentWithAppointment(
            assignee: $agent,
            preferredDate: '2026-07-08',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $todayIncident = $this->createIncidentWithAppointment(
            assignee: $agent,
            preferredDate: '2026-07-06',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $this->artisan('team-telegram:send-appointment-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Appointment Reminder Diagnostics')
            ->expectsOutputToContain('Scheduled appointments:')
            ->expectsOutputToContain('Today\'s appointments:')
            ->expectsOutputToContain('Matched reminder window:')
            ->expectsOutputToContain('Delivered:');

        $this->assertSame(2, SupportAppointment::query()->scheduled()->count());
        $this->assertNotNull($futureIncident);
        $this->assertNotNull($todayIncident);
    }

    public function test_verbose_output_explains_threshold_exclusion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 16:42:00', 'Asia/Kolkata'));

        $agent = User::factory()->create([
            'is_active' => true,
            'telegram_chat_id' => '123456',
            'telegram_notifications_enabled' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $appointment = $this->createIncidentWithAppointment(
            assignee: $agent,
            preferredDate: '2026-07-06',
            slot: SupportAppointmentTimeSlot::Morning,
        )->supportAppointments->first();

        $this->artisan('team-telegram:send-appointment-reminders -v')
            ->assertSuccessful()
            ->expectsOutputToContain("Appointment #{$appointment->id}")
            ->expectsOutputToContain('✗ Threshold window')
            ->expectsOutputToContain('Minutes until slot:')
            ->expectsOutputToContain('Morning (9');

        $this->assertNotNull($appointment);
    }

    public function test_verbose_output_explains_missing_assignee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 16:42:00', 'Asia/Kolkata'));

        $appointment = $this->createIncidentWithAppointment(
            assignee: null,
            preferredDate: '2026-07-06',
            slot: SupportAppointmentTimeSlot::Morning,
        )->supportAppointments->first();

        $this->artisan('team-telegram:send-appointment-reminders -v')
            ->assertSuccessful()
            ->expectsOutputToContain("Appointment #{$appointment->id}")
            ->expectsOutputToContain('✗ Assigned engineer')
            ->expectsOutputToContain('No assigned engineer');

        $this->assertNotNull($appointment);
    }

    private function createIncidentWithAppointment(
        ?User $assignee,
        string $preferredDate,
        SupportAppointmentTimeSlot $slot,
    ): Incident {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RD-CMD-'.uniqid(),
            'serial_number' => 'SN-CMD-001',
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Diagnostics Customer',
            'customer_email' => 'diag@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Diagnostics case',
            'description' => 'Diagnostics case.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => $preferredDate,
            'preferred_time_slot' => $slot,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        return $incident->fresh(['supportAppointments']);
    }
}
