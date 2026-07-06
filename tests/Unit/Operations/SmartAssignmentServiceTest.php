<?php

namespace Tests\Unit\Operations;

use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Operations\SmartAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_eligible_candidates_exclude_offline_and_approved_leave_users(): void
    {
        $available = $this->createAgent(TeamAvailabilityStatus::Available);
        $this->createAgent(TeamAvailabilityStatus::Offline);

        $onApprovedLeave = $this->createAgent(TeamAvailabilityStatus::Available);
        LeaveRequest::query()->create([
            'user_id' => $onApprovedLeave->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Approved leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $candidates = app(SmartAssignmentService::class)->eligibleCandidates();

        $this->assertCount(1, $candidates);
        $this->assertSame($available->id, $candidates[0]->id);
    }

    public function test_inactive_users_are_excluded(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update(['availability_status' => TeamAvailabilityStatus::Available]);

        $this->assertSame([], app(SmartAssignmentService::class)->eligibleCandidates());
    }

    private function createAgent(TeamAvailabilityStatus $status): User
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update(['availability_status' => $status]);

        return $user->fresh();
    }
}
