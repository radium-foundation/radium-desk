<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveRequestStabilizationTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $leaveService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->leaveService = app(LeaveRequestService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));
        config(['workforce_calendar.retroactive_leave_days' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_submit_rejects_overlapping_pending_leave(): void
    {
        $agent = $this->createAgent();

        $this->leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'First leave',
        ]);

        try {
            $this->leaveService->submit($agent, [
                'start_date' => '2026-07-11',
                'end_date' => '2026-07-13',
                'reason' => 'Overlapping leave',
            ]);
            $this->fail('Expected validation exception for overlapping leave.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_date', $exception->errors());
        }

        $this->assertSame(1, LeaveRequest::query()->where('user_id', $agent->id)->count());
    }

    public function test_submit_rejects_overlapping_approved_leave(): void
    {
        $agent = $this->createAgent();

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Approved leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        try {
            $this->leaveService->submit($agent, [
                'start_date' => '2026-07-12',
                'end_date' => '2026-07-14',
                'reason' => 'Overlapping leave',
            ]);
            $this->fail('Expected validation exception for overlapping approved leave.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_date', $exception->errors());
        }
    }

    public function test_submit_allows_adjacent_non_overlapping_leave(): void
    {
        $agent = $this->createAgent();

        $this->leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'First leave',
        ]);

        $second = $this->leaveService->submit($agent, [
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-14',
            'reason' => 'Second leave',
        ]);

        $this->assertSame(LeaveRequestStatus::Pending, $second->status);
        $this->assertSame(2, LeaveRequest::query()->where('user_id', $agent->id)->count());
    }

    public function test_submit_allows_rejected_leave_dates_to_be_re_requested(): void
    {
        $agent = $this->createAgent();

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Rejected leave',
            'status' => LeaveRequestStatus::Rejected,
        ]);

        $leaveRequest = $this->leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Re-requested leave',
        ]);

        $this->assertSame(LeaveRequestStatus::Pending, $leaveRequest->status);
    }

    public function test_retroactive_leave_allows_two_day_window(): void
    {
        $agent = $this->createAgent();

        $leaveRequest = $this->leaveService->submit($agent, [
            'start_date' => '2026-07-04',
            'end_date' => '2026-07-04',
            'reason' => 'Retroactive leave',
        ]);

        $this->assertSame('2026-07-04', $leaveRequest->start_date->toDateString());
    }

    public function test_retroactive_leave_rejects_dates_before_window(): void
    {
        $agent = $this->createAgent();

        try {
            $this->leaveService->submit($agent, [
                'start_date' => '2026-07-03',
                'end_date' => '2026-07-03',
                'reason' => 'Too retroactive',
            ]);
            $this->fail('Expected validation exception for retroactive leave outside window.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_date', $exception->errors());
        }
    }

    public function test_approve_rejects_when_another_approved_leave_overlaps(): void
    {
        $agent = $this->createAgent();
        $operationsAdmin = $this->createOperationsAdmin();

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Existing approved leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $pendingLeave = LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-11',
            'end_date' => '2026-07-13',
            'reason' => 'Legacy overlapping pending leave',
            'status' => LeaveRequestStatus::Pending,
        ]);

        try {
            $this->leaveService->approve($pendingLeave, $operationsAdmin, 'Should fail');
            $this->fail('Expected validation exception when approving overlapping leave.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_date', $exception->errors());
        }
    }

    public function test_double_approve_is_rejected(): void
    {
        $agent = $this->createAgent();
        $operationsAdmin = $this->createOperationsAdmin();

        $leaveRequest = $this->leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        $this->leaveService->approve($leaveRequest, $operationsAdmin, 'Approved once');

        try {
            $this->leaveService->approve($leaveRequest->fresh(), $operationsAdmin, 'Approved twice');
            $this->fail('Expected validation exception for double approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }

    public function test_submit_writes_namespaced_audit_event_with_legacy_reference(): void
    {
        $agent = $this->createAgent();

        $leaveRequest = $this->leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        $audit = AuditLog::query()
            ->where('event', 'workforce.leave.submitted')
            ->where('auditable_id', $leaveRequest->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('leave.submitted', $audit->new_values['legacy_event'] ?? null);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'leave.submitted',
            'auditable_id' => $leaveRequest->id,
        ]);
    }

    public function test_historical_audit_entries_remain_unchanged(): void
    {
        $agent = $this->createAgent();

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'leave.submitted',
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => 999,
            'new_values' => ['status' => 'pending'],
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'leave.submitted',
            'auditable_id' => 999,
        ]);
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
}
