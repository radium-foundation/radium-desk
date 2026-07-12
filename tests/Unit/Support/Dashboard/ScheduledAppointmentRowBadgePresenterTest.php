<?php

namespace Tests\Unit\Support\Dashboard;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Support\Dashboard\ScheduledAppointmentRowBadgePresenter;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduledAppointmentRowBadgePresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_future_appointment_shows_scheduled_badge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $incident = $this->createIncidentWithAppointment(
            preferredDate: now()->addDay()->toDateString(),
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $badge = app(ScheduledAppointmentRowBadgePresenter::class)->present($incident);

        $this->assertNotNull($badge);
        $this->assertSame('Scheduled', $badge['label']);

        Carbon::setTestNow();
    }

    public function test_todays_active_slot_shows_due_now_badge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $incident = $this->createIncidentWithAppointment(
            preferredDate: now()->toDateString(),
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $badge = app(ScheduledAppointmentRowBadgePresenter::class)->present($incident);

        $this->assertNotNull($badge);
        $this->assertSame('Due Now', $badge['label']);

        Carbon::setTestNow();
    }

    public function test_appointment_starting_within_thirty_minutes_shows_starting_soon_badge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:30:00', 'Asia/Kolkata'));

        $incident = $this->createIncidentWithAppointment(
            preferredDate: now()->toDateString(),
            slot: SupportAppointmentTimeSlot::Afternoon,
        );

        $badge = app(ScheduledAppointmentRowBadgePresenter::class)->present($incident);

        $this->assertNotNull($badge);
        $this->assertSame('Starting Soon', $badge['label']);

        Carbon::setTestNow();
    }

    public function test_past_incomplete_appointment_shows_missed_badge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $incident = $this->createIncidentWithAppointment(
            preferredDate: now()->subDay()->toDateString(),
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $badge = app(ScheduledAppointmentRowBadgePresenter::class)->present($incident);

        $this->assertNotNull($badge);
        $this->assertSame('Missed', $badge['label']);

        Carbon::setTestNow();
    }

    public function test_slot_ended_today_shows_follow_up_required_badge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 15:30:00', 'Asia/Kolkata'));

        $incident = $this->createIncidentWithAppointment(
            preferredDate: now()->toDateString(),
            slot: SupportAppointmentTimeSlot::Afternoon,
        );

        $badge = app(ScheduledAppointmentRowBadgePresenter::class)->present($incident);

        $this->assertNotNull($badge);
        $this->assertSame('Follow-up Required', $badge['label']);
        $this->assertSame('Today • Afternoon', $badge['schedule_summary']);

        Carbon::setTestNow();
    }

    public function test_sorts_incidents_by_appointment_urgency(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $presenter = app(ScheduledAppointmentRowBadgePresenter::class);

        $scheduledFuture = $this->createIncidentWithAppointment(
            preferredDate: now()->addDay()->toDateString(),
            slot: SupportAppointmentTimeSlot::Morning,
            orderId: 'RD-SORT-FUTURE',
        );
        $dueNow = $this->createIncidentWithAppointment(
            preferredDate: now()->toDateString(),
            slot: SupportAppointmentTimeSlot::Morning,
            orderId: 'RD-SORT-DUE',
        );
        $missed = $this->createIncidentWithAppointment(
            preferredDate: now()->subDay()->toDateString(),
            slot: SupportAppointmentTimeSlot::Morning,
            orderId: 'RD-SORT-MISSED',
        );

        $sorted = $presenter->sortIncidents(collect([$scheduledFuture, $dueNow, $missed]));

        $this->assertSame(
            [$missed->id, $dueNow->id, $scheduledFuture->id],
            $sorted->pluck('id')->all(),
        );

        Carbon::setTestNow();
    }

    private function createIncidentWithAppointment(
        string $preferredDate,
        SupportAppointmentTimeSlot $slot,
        ?string $orderId = null,
    ): Incident {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => $orderId ?? ('RD-BADGE-'.uniqid()),
            'serial_number' => 'SN-BADGE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Scheduled badge test',
            'description' => 'Scheduled badge test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => $preferredDate,
            'preferred_time_slot' => $slot,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        return $incident->fresh(['supportAppointments', 'assignee']);
    }
}
