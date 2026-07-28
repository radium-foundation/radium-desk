<?php

namespace Tests\Unit\Dashboard;

use App\Enums\IncidentSource;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamActivityPreviousActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-28 10:30:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_exposes_previous_activity_timestamp_when_prior_event_occurred_today(): void
    {
        $agent = User::factory()->create([
            'is_active' => true,
            'name' => 'Audit Agent',
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-PREV-1',
            'serial_number' => 'SN-PREV',
            'customer_name' => 'Previous Activity Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Previous activity test',
            'description' => 'Previous activity test.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        $olderAt = now()->subMinutes(18);
        $latestAt = now()->subMinutes(2);

        $older = AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
        $older->created_at = $olderAt;
        $older->saveQuietly();

        $latest = AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
        $latest->created_at = $latestAt;
        $latest->saveQuietly();

        $row = collect(app(TeamActivityPanelService::class)->build()->agents)
            ->firstWhere('id', $agent->id);

        $this->assertNotNull($row);
        $this->assertTrue($latestAt->equalTo($row->latestActivityAt));
        $this->assertTrue($olderAt->equalTo($row->previousActivityAt));
        $this->assertSame('2 min', display_team_activity_elapsed($row->latestActivityAt));
        $this->assertSame('18 min', display_team_activity_elapsed($row->previousActivityAt));
    }
}
