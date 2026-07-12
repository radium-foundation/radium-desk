<?php

namespace Tests\Unit\Dashboard;

use App\Data\Dashboard\AgentNextAppointment;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Dashboard\AgentNextAppointmentResolver;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AgentNextAppointmentResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_returns_null_when_agent_has_no_appointments_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent();
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->createIncident('RD-NO-APPT', $creator, $agent);

        $appointment = app(AgentNextAppointmentResolver::class)->resolve(
            DashboardSnapshot::load(),
            $agent,
        );

        $this->assertNull($appointment);

        Carbon::setTestNow();
    }

    public function test_returns_next_upcoming_appointment_for_assigned_agent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:30:00', 'Asia/Kolkata'));

        $agent = $this->createAgent();
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-APPT-1',
            'serial_number' => 'SN-APPT-1',
            'customer_name' => 'Rakesh Sharma',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Scheduled support',
            'description' => 'Scheduled support.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Afternoon,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $appointment = app(AgentNextAppointmentResolver::class)->resolve(
            DashboardSnapshot::load(),
            $agent,
        );

        $this->assertInstanceOf(AgentNextAppointment::class, $appointment);
        $this->assertSame($incident->id, $appointment->incidentId);
        $this->assertSame('Rakesh Sharma', $appointment->customerName);
        $this->assertSame('RBX 110', $appointment->deviceModel);
        $this->assertFalse($appointment->isOverdue);
        $this->assertSame('12:00 PM', $appointment->timeLabel());
        $this->assertSame('Starts in 30 minutes', $appointment->startsInLabel());

        Carbon::setTestNow();
    }

    public function test_marks_overdue_appointment_when_slot_has_started(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:15:00', 'Asia/Kolkata'));

        $agent = $this->createAgent();
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->createIncident('RD-OVERDUE', $creator, $agent);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Afternoon,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $appointment = app(AgentNextAppointmentResolver::class)->resolve(
            DashboardSnapshot::load(),
            $agent,
        );

        $this->assertNotNull($appointment);
        $this->assertTrue($appointment->isOverdue);
        $this->assertTrue($appointment->isImminent());

        Carbon::setTestNow();
    }

    private function createAgent(): User
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    private function createIncident(string $orderId, User $creator, ?User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Agent appointment test',
            'description' => 'Agent appointment test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }
}
