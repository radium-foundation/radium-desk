<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceCasePrioritySortingTest extends TestCase
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

    public function test_scheduled_rd_case_sorts_above_rd_without_appointment_at_same_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $createdAt = now()->subHours(2);

        $scheduledOrder = Order::query()->create([
            'order_id' => 'RD-SCHEDULED',
            'serial_number' => 'SN-SCHEDULED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $plainOrder = Order::query()->create([
            'order_id' => 'RD-PLAIN',
            'serial_number' => 'SN-PLAIN',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $scheduledIncident = $this->createIncident($scheduledOrder, 'SC-SCHEDULED', $agent, $createdAt);
        $plainIncident = $this->createIncident($plainOrder, 'SC-PLAIN', $agent, $createdAt);

        SupportAppointment::query()->create([
            'incident_id' => $scheduledIncident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
            'status' => 'scheduled',
        ]);

        $sorted = app(DashboardService::class)->recentServiceCases('all', 10);

        $this->assertSame([
            'SC-SCHEDULED',
            'SC-PLAIN',
        ], $sorted->pluck('reference_no')->all());
    }

    public function test_rd_missing_serial_sorts_above_unknown_inq_at_same_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $createdAt = now()->subHours(2);

        $missingSerialOrder = Order::query()->create([
            'order_id' => 'RD-MISSING-SERIAL',
            'serial_number' => '',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $inquiryOrder = Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference('SC-INQ-UNK'),
            'serial_number' => '',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->createIncident($missingSerialOrder, 'SC-MISSING-SERIAL', $agent, $createdAt);
        $this->createIncident($inquiryOrder, 'SC-INQ-UNK', $agent, $createdAt);

        $sorted = app(DashboardService::class)->recentServiceCases('all', 10);

        $this->assertSame([
            'SC-MISSING-SERIAL',
            'SC-INQ-UNK',
        ], $sorted->pluck('reference_no')->all());
    }

    public function test_sla_breach_beats_business_priority(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $rdOrder = Order::query()->create([
            'order_id' => 'RD-WITHIN-SLA',
            'serial_number' => 'SN-WITHIN',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $inqOrder = Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference('SC-INQ-OVER'),
            'serial_number' => '',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->createIncident($rdOrder, 'SC-WITHIN-SLA', $agent, now()->subHours(2));
        $this->createIncident($inqOrder, 'SC-INQ-OVER', $agent, now()->subHours(50));

        $sorted = app(DashboardService::class)->recentServiceCases('all', 10);

        $this->assertSame([
            'SC-INQ-OVER',
            'SC-WITHIN-SLA',
        ], $sorted->pluck('reference_no')->all());
    }

    public function test_paused_sla_remains_last_among_active_cases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $pausedOrder = Order::query()->create([
            'order_id' => 'RD-PAUSED',
            'serial_number' => 'SN-PAUSED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $inqOrder = Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference('SC-INQ-LOW'),
            'serial_number' => '',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $pausedIncident = $this->createIncident($pausedOrder, 'SC-PAUSED', $agent, now()->subHours(60));
        $this->createIncident($inqOrder, 'SC-INQ-LOW', $agent, now()->subHours(2));

        IncidentWaitingState::query()->create([
            'incident_id' => $pausedIncident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subDay(),
            'sla_paused' => true,
            'created_by' => $agent->id,
        ]);

        $sorted = app(DashboardService::class)->recentServiceCases('all', 10);

        $this->assertSame([
            'SC-INQ-LOW',
            'SC-PAUSED',
        ], $sorted->pluck('reference_no')->all());
    }

    public function test_dashboard_sort_rank_preserves_existing_sla_escalation_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SLA-SORT',
            'serial_number' => 'SN-SLA-SORT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $cases = [
            ['ref' => 'SC-SLA-WITHIN', 'hours' => 2, 'high' => false],
            ['ref' => 'SC-SLA-WARN-N', 'hours' => 30, 'high' => false],
            ['ref' => 'SC-SLA-OVER-N', 'hours' => 50, 'high' => false],
            ['ref' => 'SC-SLA-WARN-HP', 'hours' => 5, 'high' => true],
            ['ref' => 'SC-SLA-OVER-HP', 'hours' => 10, 'high' => true],
        ];

        foreach ($cases as $case) {
            $createdAt = now()->subHours($case['hours']);

            $incident = Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $case['ref'],
                'category' => 'General',
                'source' => IncidentSource::Internal,
                'title' => $case['ref'],
                'description' => 'SLA sort test.',
                'status' => IncidentStatus::Open,
                'high_priority' => $case['high'],
                'created_by' => $agent->id,
            ]);

            $incident->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        $sorted = app(DashboardService::class)->recentServiceCases('all', 10);

        $this->assertSame([
            'SC-SLA-OVER-HP',
            'SC-SLA-WARN-HP',
            'SC-SLA-OVER-N',
            'SC-SLA-WARN-N',
            'SC-SLA-WITHIN',
        ], $sorted->pluck('reference_no')->all());
    }

    private function createIncident(
        Order $order,
        string $referenceNo,
        User $agent,
        Carbon $createdAt,
    ): Incident {
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => $referenceNo,
            'description' => 'Priority sort test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $incident->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $incident;
    }
}
