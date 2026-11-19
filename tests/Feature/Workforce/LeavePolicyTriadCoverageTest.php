<?php

namespace Tests\Feature\Workforce;

use App\Models\User;
use App\Services\Operations\LeavePolicy\LeaveCoverageGuard;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Triad coverage using Owner-provided email identities (P-19-17).
 * Production user IDs are verified separately; tests use the same addresses on fresh users.
 */
class LeavePolicyTriadCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> Owner-provided triad emails (P-19-17) */
    private const TRIAD_EMAILS = [
        'shubhanshi@radiumbox.com',
        'abhinavjharadiumbox@gmail.com',
        'kanchan@radiumbox.com',
    ];

    private LeaveRequestService $leaveService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->leaveService = app(LeaveRequestService::class);

        config([
            'leave_policy.coverage.triad_emails' => self::TRIAD_EMAILS,
            'leave_policy.coverage.admin_pair_emails' => [],
            'leave_policy.auto_approval.enabled' => false,
        ]);
    }

    public function test_triad_configuration_resolves_three_active_users(): void
    {
        $this->seedTriadMembers();
        $guard = app(LeaveCoverageGuard::class);

        $status = $guard->configurationStatus();
        $this->assertTrue($status['triad_configured']);
        $this->assertCount(3, $guard->resolveTriadUserIds());
    }

    public function test_triad_pair_shubhanshi_abhinav_blocks_third_on_same_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

        $users = $this->seedTriadMembers();
        $approver = $this->approver();

        foreach ([$users['shubhanshi'], $users['abhinav']] as $member) {
            $leave = $this->leaveService->submit($member, [
                'start_date' => '2026-08-07',
                'end_date' => '2026-08-07',
                'reason' => 'Triad leave',
            ]);
            $this->leaveService->approve($leave, $approver, 'Ok');
        }

        $this->expectException(ValidationException::class);
        $this->leaveService->submit($users['kanchan'], [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Third on same day',
        ]);
    }

    public function test_triad_all_three_manual_exception_still_blocked_on_approve(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

        $users = $this->seedTriadMembers();
        $approver = $this->approver();

        foreach ([$users['shubhanshi'], $users['abhinav']] as $member) {
            $leave = $this->leaveService->submit($member, [
                'start_date' => '2026-08-07',
                'end_date' => '2026-08-07',
                'reason' => 'Approved',
            ]);
            $this->leaveService->approve($leave, $approver, 'Ok');
        }

        $pending = $this->leaveService->submit($users['kanchan'], [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Manual review',
            'manual_exception' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->leaveService->approve($pending, $approver, 'Override', [
            'authorized_override' => true,
            'authorized_override_reason' => 'Must not waive triad coverage',
        ]);
    }

    /**
     * @return array{shubhanshi: User, abhinav: User, kanchan: User}
     */
    private function seedTriadMembers(): array
    {
        $shubhanshi = User::factory()->create(['email' => self::TRIAD_EMAILS[0], 'is_active' => true]);
        $abhinav = User::factory()->create(['email' => self::TRIAD_EMAILS[1], 'is_active' => true]);
        $kanchan = User::factory()->create(['email' => self::TRIAD_EMAILS[2], 'is_active' => true]);
        foreach ([$shubhanshi, $abhinav, $kanchan] as $user) {
            $user->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        }

        return [
            'shubhanshi' => $shubhanshi,
            'abhinav' => $abhinav,
            'kanchan' => $kanchan,
        ];
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
