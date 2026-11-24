<?php

namespace Tests\Feature\Workforce;

use App\Enums\LeaveDuration;
use App\Models\User;
use App\Services\Operations\LeavePolicy\LeaveAutoApprovalEvaluator;
use App\Services\Operations\LeavePolicy\LeaveSundaySandwichResolver;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeavePolicyControlsTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $leaveService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->leaveService = app(LeaveRequestService::class);

        config([
            'leave_policy.coverage.triad_emails' => [],
            'leave_policy.coverage.admin_pair_emails' => [],
            'leave_policy.auto_approval.enabled' => false,
        ]);
    }

    public function test_employee_half_day_submission_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $this->expectException(ValidationException::class);

        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'duration' => LeaveDuration::HalfDay->value,
            'reason' => 'Should fail',
        ]);
    }

    public function test_saturday_short_notice_adds_sunday_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-08',
            'end_date' => '2026-08-08',
            'reason' => 'Saturday leave',
        ]);

        $this->assertSame('2026-08-08', $leave->start_date->toDateString());
        $this->assertSame('2026-08-09', $leave->policy_effective_end_date->toDateString());
        $this->assertTrue((bool) data_get($leave->policy_metadata, 'sunday_sandwich.applied'));
    }

    public function test_separate_saturday_and_monday_only_one_sunday_sandwich(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $approver = $this->approver();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $sat = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-08',
            'end_date' => '2026-08-08',
            'reason' => 'Saturday',
        ]);
        $this->leaveService->approve($sat, $approver, 'Approved');

        $mon = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-10',
            'reason' => 'Monday',
        ]);

        $this->assertSame('2026-08-10', $mon->policy_effective_start_date->toDateString());
        $this->assertSame('2026-08-10', $mon->policy_effective_end_date->toDateString());
        $this->assertEmpty(data_get($mon->policy_metadata, 'sunday_sandwich.extra_sundays', []));
    }

    public function test_admin_pair_cannot_both_be_on_leave_same_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

        config([
            'leave_policy.coverage.admin_pair_emails' => [
                'dileep@test.com',
                'avinash@test.com',
            ],
        ]);

        $approver = $this->approver();
        $dileep = User::factory()->create(['email' => 'dileep@test.com', 'is_active' => true]);
        $avinash = User::factory()->create(['email' => 'avinash@test.com', 'is_active' => true]);
        $dileep->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $first = $this->leaveService->submit($dileep, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Admin leave',
        ]);
        $this->leaveService->approve($first, $approver, 'Ok');

        $this->expectException(ValidationException::class);
        $this->leaveService->submit($avinash, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Conflict',
        ]);
    }

    public function test_manual_exception_monthly_cap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Kolkata'));

        $approver = $this->approver();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        foreach (['2026-08-05', '2026-08-12'] as $day) {
            $leave = $this->leaveService->submit($agent, [
                'start_date' => $day,
                'end_date' => $day,
                'reason' => 'Exception',
                'manual_exception' => true,
            ]);
            $this->leaveService->approve($leave, $approver, 'Approved exception');
        }

        $this->expectException(ValidationException::class);
        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-19',
            'end_date' => '2026-08-19',
            'reason' => 'Third exception',
            'manual_exception' => true,
        ]);
    }

    public function test_auto_approval_enabled_without_balance_ledger_for_structural_pilot(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $evaluator = app(LeaveAutoApprovalEvaluator::class);

        $this->assertTrue($evaluator->isEnabled());
        $this->assertFalse($evaluator->balanceLedgerAvailable());
    }

    public function test_auto_approval_disabled_when_feature_flag_off(): void
    {
        config(['leave_policy.auto_approval.enabled' => false]);

        $evaluator = app(LeaveAutoApprovalEvaluator::class);

        $this->assertFalse($evaluator->isEnabled());
        $this->assertStringContainsString('disabled', strtolower($evaluator->blockReasonWhenDisabled()));
    }

    public function test_authorized_override_cannot_bypass_admin_pair_coverage_on_approve(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

        config([
            'leave_policy.coverage.admin_pair_emails' => [
                'dileep@test.com',
                'avinash@test.com',
            ],
        ]);

        $approver = $this->approver();
        $dileep = User::factory()->create(['email' => 'dileep@test.com', 'is_active' => true]);
        $avinash = User::factory()->create(['email' => 'avinash@test.com', 'is_active' => true]);
        $dileep->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $first = $this->leaveService->submit($dileep, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Admin leave',
        ]);
        $this->leaveService->approve($first, $approver, 'Ok');

        $conflict = $this->leaveService->submit($avinash, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Needs review',
            'manual_exception' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->leaveService->approve($conflict, $approver, 'Override attempt', [
            'authorized_override' => true,
            'authorized_override_reason' => 'Attempt to bypass coverage',
        ]);
    }

    public function test_authorized_override_allows_third_manual_exception_in_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Kolkata'));

        $approver = $this->approver();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $pending = [];
        foreach (['2026-08-05', '2026-08-12', '2026-08-19'] as $day) {
            $pending[] = $this->leaveService->submit($agent, [
                'start_date' => $day,
                'end_date' => $day,
                'reason' => 'Exception',
                'manual_exception' => true,
            ]);
        }

        $this->leaveService->approve($pending[0], $approver, 'One');
        $this->leaveService->approve($pending[1], $approver, 'Two');

        $this->expectException(ValidationException::class);
        $this->leaveService->approve($pending[2], $approver, 'Three without override');

        $approved = $this->leaveService->approve($pending[2], $approver, 'Three with override', [
            'authorized_override' => true,
            'authorized_override_reason' => 'Management authorized monthly exception cap override',
        ]);

        $this->assertTrue($approved->authorized_override);
        $this->assertSame(LeaveDuration::FullDay, $approved->duration);
    }

    public function test_incomplete_triad_configuration_blocks_listed_member(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

        config([
            'leave_policy.coverage.triad_emails' => [
                'shubhanshi@test.com',
                'abhinav@test.com',
            ],
        ]);

        $member = User::factory()->create(['email' => 'shubhanshi@test.com', 'is_active' => true]);
        $member->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $this->expectException(ValidationException::class);
        $this->leaveService->submit($member, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Incomplete triad',
        ]);
    }

    public function test_seven_day_notice_skips_sunday_sandwich(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Kolkata'));

        $resolver = app(LeaveSundaySandwichResolver::class);
        $result = $resolver->resolve(
            Carbon::parse('2026-08-08')->startOfDay(),
            Carbon::parse('2026-08-08')->startOfDay(),
            Carbon::parse('2026-08-01 09:00:00'),
        );

        $this->assertFalse($result['applied']);
        $this->assertSame([], $result['dates']);
    }

    private function approver(): User
    {
        $operationsAdmin = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $operationsAdmin;
    }
}
