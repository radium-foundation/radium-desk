<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveRequestAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));
        config(['workforce_calendar.retroactive_leave_days' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_view_leave_request(): void
    {
        $agent = $this->createAgent();
        $leaveRequest = $this->createLeaveRequest($agent);

        $this->actingAs($agent)
            ->get(route('leave-requests.show', $leaveRequest))
            ->assertOk()
            ->assertSee($leaveRequest->reason);
    }

    public function test_reviewer_can_view_leave_request(): void
    {
        $agent = $this->createAgent();
        $operationsAdmin = $this->createOperationsAdmin();
        $leaveRequest = $this->createLeaveRequest($agent);

        $this->actingAs($operationsAdmin)
            ->get(route('leave-requests.show', $leaveRequest))
            ->assertOk()
            ->assertSee($agent->name);
    }

    public function test_unrelated_user_cannot_view_leave_request(): void
    {
        $requester = $this->createAgent();
        $otherAgent = $this->createAgent();
        $leaveRequest = $this->createLeaveRequest($requester);

        $this->actingAs($otherAgent)
            ->get(route('leave-requests.show', $leaveRequest))
            ->assertForbidden();
    }

    private function createAgent(): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    private function createOperationsAdmin(): User
    {
        $operationsAdmin = User::factory()->create(['is_active' => true]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $operationsAdmin;
    }

    private function createLeaveRequest(User $requester): LeaveRequest
    {
        return app(LeaveRequestService::class)->submit($requester, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);
    }
}
