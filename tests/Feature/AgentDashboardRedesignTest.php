<?php

namespace Tests\Feature;

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
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AgentDashboardRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_agent_dashboard_shows_three_action_cards(): void
    {
        $agent = $this->createAgent();

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('agent-kpi-grid', false)
            ->assertSee('Assigned Cases')
            ->assertSee('Action Required')
            ->assertSee('No Appointments Today')
            ->assertSee('dashboard-quick-filter--always-open', false)
            ->assertSee('Search order, case or customer...')
            ->assertSee('agent-kpi-tile--static', false)
            ->assertDontSee('View Calendar')
            ->assertDontSee('Open →')
            ->assertDontSee('Review →')
            ->assertSee('Resume Last Customer', false)
            ->assertDontSee('My Active Work')
            ->assertDontSee('My Scheduled Today')
            ->assertDontSee('My Waiting Follow-ups')
            ->assertDontSee('My Completed Today')
            ->assertDontSee('dashboard-kpi-label">Refunds<', false);
    }

    public function test_agent_dashboard_shows_renamed_queue_tabs_and_hides_zero_counts(): void
    {
        $agent = $this->createAgent();

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-case-filter-chip__label">Active<', false)
            ->assertDontSee('dashboard-case-filter-chip__label">My Work<', false)
            ->assertDontSee('dashboard-case-filter-chip__label">Waiting Customer<', false)
            ->assertDontSee('dashboard-case-filter-chip__label">Scheduled<', false)
            ->assertDontSee('dashboard-case-filter-chip__label">Today<', false)
            ->assertDontSee('dashboard-case-filter-chip__label">Appointments<', false)
            ->assertDontSee('dashboard-case-filter-chip__label">Waiting<', false)
            ->assertDontSee('dashboard-case-filter-chip__label">Done<', false);
    }

    public function test_needs_attention_count_combines_attention_and_waiting_follow_ups(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent();
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $attentionCase = $this->createIncident('RD-ATT-1', $creator, $agent);
        $attentionCase->forceFill([
            'created_at' => now()->subHours(72),
            'updated_at' => now()->subHours(72),
        ])->save();

        $waitingCase = $this->createIncident('RD-WAIT-1', $creator, $agent);
        IncidentWaitingState::query()->create([
            'incident_id' => $waitingCase->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        $stats = app(DashboardService::class)->statsFor($agent);

        $this->assertGreaterThanOrEqual(1, $stats['my_attention']);
        $this->assertGreaterThanOrEqual(1, $stats['my_waiting_follow_ups']);
        $this->assertSame(
            $stats['my_attention'] + $stats['my_waiting_follow_ups'],
            $stats['my_needs_attention'],
        );

        Carbon::setTestNow();
    }

    public function test_next_appointment_is_included_in_stats_and_dashboard_markup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:30:00', 'Asia/Kolkata'));

        $agent = $this->createAgent();
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-NEXT-1',
            'serial_number' => 'SN-NEXT-1',
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

        $stats = app(DashboardService::class)->statsFor($agent);

        $this->assertIsArray($stats['next_appointment']);
        $this->assertSame($incident->id, $stats['next_appointment']['incident_id']);
        $this->assertSame('Rakesh Sharma', $stats['next_appointment']['customer_name']);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('12:00 PM')
            ->assertSee('Rakesh Sharma')
            ->assertSee('data-next-appointment', false)
            ->assertSee('agent-appointment-banner-sticky-host', false);

        Carbon::setTestNow();
    }

    public function test_scheduled_queue_tab_is_labelled_appointments_for_agents(): void
    {
        $agent = $this->createAgent();
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->createIncident('RD-APPT-TAB', $creator, $agent);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard', ['queue' => 'scheduled']))
            ->assertOk()
            ->assertSee('dashboard-case-filter-chip__label">Appointments<', false)
            ->assertDontSee('dashboard-case-filter-chip__label">Today<', false);
    }

    public function test_needs_attention_card_shows_breakdown_lines(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent();
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $attentionCase = $this->createIncident('RD-BRK-1', $creator, $agent);
        $attentionCase->forceFill([
            'created_at' => now()->subHours(72),
            'updated_at' => now()->subHours(72),
        ])->save();

        $waitingCase = $this->createIncident('RD-BRK-2', $creator, $agent);
        IncidentWaitingState::query()->create([
            'incident_id' => $waitingCase->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        $stats = app(DashboardService::class)->statsFor($agent);
        $breakdown = $stats['my_needs_attention_breakdown'];

        $this->assertSame(
            $stats['my_needs_attention'],
            $breakdown['overdue'] + $breakdown['waiting_follow_ups'] + $breakdown['escalations'],
        );

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('agent-kpi-tile__chips', false)
            ->assertSee('agent-kpi-chip--waiting', false);

        Carbon::setTestNow();
    }

    public function test_imminent_appointment_renders_sticky_banner_host(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:30:00', 'Asia/Kolkata'));

        $agent = $this->createAgent();
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-STICKY-1',
            'serial_number' => 'SN-STICKY-1',
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

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('agent-appointment-banner-sticky-host', false)
            ->assertSee('agent-kpi-grid--two-up', false)
            ->assertSee('data-agent-open-appointment="true"', false);

        Carbon::setTestNow();
    }

    public function test_live_refresh_includes_next_appointment_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:30:00', 'Asia/Kolkata'));

        $agent = $this->createAgent();
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->createIncident('RD-LIVE-1', $creator, $agent);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Afternoon,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $this->actingAs($agent)
            ->getJson(route('dashboard.live'))
            ->assertOk()
            ->assertJsonPath('next_appointment.incident_id', $incident->id);

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
            'title' => 'Agent dashboard redesign test',
            'description' => 'Agent dashboard redesign test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }
}
