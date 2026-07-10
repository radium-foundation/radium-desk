<?php

namespace Tests\Unit\Operations;

use App\Enums\CompanyHolidayType;
use App\Enums\IncidentSource;
use App\Enums\LeaveRequestStatus;
use App\Models\CompanyHoliday;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\BusinessHoursSlaCalculator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessHoursSlaCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private BusinessHoursSlaCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->calculator = app(BusinessHoursSlaCalculator::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_counts_only_business_hours_within_same_day(): void
    {
        $schedule = $this->standardSchedule();
        $from = Carbon::parse('2026-07-06 10:00:00');
        $to = Carbon::parse('2026-07-06 12:00:00');

        $this->assertSame(120, $this->calculator->elapsedBusinessMinutes($from, $to, null, $schedule));
        $this->assertSame(2, intdiv(120, 60));
    }

    public function test_excludes_lunch_from_elapsed_minutes(): void
    {
        $schedule = $this->standardSchedule();
        $from = Carbon::parse('2026-07-06 13:00:00');
        $to = Carbon::parse('2026-07-06 14:00:00');

        $this->assertSame(30, $this->calculator->elapsedBusinessMinutes($from, $to, null, $schedule));
    }

    public function test_excludes_weekly_off_day(): void
    {
        $schedule = $this->standardSchedule([Carbon::SUNDAY]);
        $from = Carbon::parse('2026-07-11 10:00:00'); // Saturday
        $to = Carbon::parse('2026-07-13 10:00:00'); // Monday

        $saturdayMinutes = 8 * 60 - 30; // 09:00-18:00 minus 30 min lunch
        $mondayMinutes = 60;

        $this->assertSame(
            $saturdayMinutes + $mondayMinutes,
            $this->calculator->elapsedBusinessMinutes($from, $to, null, $schedule),
        );
    }

    public function test_excludes_company_holiday(): void
    {
        CompanyHoliday::query()->create([
            'holiday_date' => '2026-07-07',
            'name' => 'Ops Holiday',
            'type' => CompanyHolidayType::National,
        ]);

        $schedule = $this->standardSchedule();
        $from = Carbon::parse('2026-07-06 10:00:00');
        $to = Carbon::parse('2026-07-08 10:00:00');

        $mondayMinutes = (3 * 60) + (3 * 60); // 10:00-13:30 and 14:00-17:00? Wait 10-18 minus lunch
        // Mon 10:00-18:00 = 7.5h = 450 min
        // Tue holiday = 0
        // Wed 09:00-10:00 = 60 min
        $expected = 450 + 60;

        $this->assertSame(
            $expected,
            $this->calculator->elapsedBusinessMinutes($from, $to, null, $schedule),
        );
    }

    public function test_excludes_assignee_approved_leave(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $schedule = $this->createScheduleFor($agent);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-07',
            'end_date' => '2026-07-07',
            'reason' => 'Approved leave',
            'status' => LeaveRequestStatus::Approved,
            'reviewed_by' => User::factory()->create()->id,
            'reviewed_at' => now(),
            'review_notes' => 'Approved',
        ]);

        $from = Carbon::parse('2026-07-06 10:00:00');
        $to = Carbon::parse('2026-07-08 10:00:00');

        $this->assertSame(
            450 + 60,
            $this->calculator->elapsedBusinessMinutes($from, $to, $agent, $schedule),
        );
    }

    public function test_unassigned_incident_uses_default_schedule(): void
    {
        $incident = $this->createIncident(null);
        $incident->forceFill(['created_at' => Carbon::parse('2026-07-06 10:00:00')])->saveQuietly();

        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00'));

        $this->assertSame(2, $this->calculator->elapsedBusinessHours($incident));
    }

    public function test_assigned_incident_uses_assignee_schedule(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $this->createScheduleFor($agent, [Carbon::MONDAY, Carbon::TUESDAY, Carbon::WEDNESDAY, Carbon::THURSDAY, Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY]);

        $incident = $this->createIncident($agent);
        $incident->forceFill(['created_at' => Carbon::parse('2026-07-06 10:00:00')])->saveQuietly();

        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00'));

        $this->assertSame(0, $this->calculator->elapsedBusinessHours($incident));
    }

    public function test_overnight_shift_counts_post_midnight_minutes(): void
    {
        $agent = User::factory()->create();
        $schedule = TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '22:00:00',
            'work_end_time' => '06:00:00',
            'lunch_start_time' => null,
            'lunch_end_time' => null,
            'short_break_count' => 0,
            'short_break_minutes' => 0,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $from = Carbon::parse('2026-07-06 23:00:00');
        $to = Carbon::parse('2026-07-07 02:00:00');

        $this->assertSame(180, $this->calculator->elapsedBusinessMinutes($from, $to, $agent, $schedule));
    }

    public function test_is_disabled_by_default(): void
    {
        config(['sla.business_hours_enabled' => false]);

        $this->assertFalse($this->calculator->isEnabled());
    }

    /**
     * @param  list<int>  $weeklyOffDays
     */
    private function standardSchedule(array $weeklyOffDays = [Carbon::SUNDAY]): TeamMemberWorkSchedule
    {
        return new TeamMemberWorkSchedule([
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => $weeklyOffDays,
        ]);
    }

    /**
     * @param  list<int>  $weeklyOffDays
     */
    private function createScheduleFor(User $user, array $weeklyOffDays = [Carbon::SUNDAY]): TeamMemberWorkSchedule
    {
        return TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => $weeklyOffDays,
        ]);
    }

    private function createIncident(?User $assignee): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-BHSLA-'.uniqid(),
            'serial_number' => 'SN-BHSLA',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-BHSLA-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Business hours SLA test',
            'description' => 'Business hours SLA test.',
            'status' => 'open',
            'created_by' => $creator->id,
            'assigned_to_user_id' => $assignee?->id,
        ])->load('order');
    }
}
