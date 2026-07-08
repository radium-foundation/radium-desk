<?php

namespace Tests\Unit\Operations;

use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Operations\OperationsAssignmentEligibilityService;
use App\Services\Operations\PresenceEngineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OperationsAssignmentEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private OperationsAssignmentEligibilityService $eligibility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->eligibility = app(OperationsAssignmentEligibilityService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_logged_out_available_user_is_not_eligible(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);

        $this->assertFalse($this->eligibility->isEligible($agent));
        $this->assertNull(WorkSession::query()->where('user_id', $agent->id)->first());
    }

    public function test_logged_in_available_user_is_eligible(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        $this->openWorkSession($agent);

        $this->assertTrue($this->eligibility->isEligible($agent));
    }

    public function test_busy_user_with_active_session_is_eligible(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Busy);
        $this->openWorkSession($agent);

        $this->assertTrue($this->eligibility->isEligible($agent));
    }

    public function test_approved_leave_blocks_assignment(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        $this->openWorkSession($agent);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Approved leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->assertFalse($this->eligibility->isEligible($agent));
    }

    public function test_admin_is_excluded_from_normal_assignment(): void
    {
        $admin = User::factory()->create([
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->openWorkSession($admin);

        $this->assertFalse($this->eligibility->isEligible($admin));
        $this->assertTrue($this->eligibility->isEligibleWithOverride($admin, allowOverride: true));
    }

    private function createScheduledAgent(TeamAvailabilityStatus $status): User
    {
        $user = User::factory()->create([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $user->fresh(['workSchedule']);
    }

    private function openWorkSession(User $user): void
    {
        app(PresenceEngineService::class)->startSession($user);
    }
}
