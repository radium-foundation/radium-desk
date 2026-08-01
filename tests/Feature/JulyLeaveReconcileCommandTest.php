<?php

namespace Tests\Feature;

use App\Enums\AttendanceMatrixCellKind;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Enums\WorkSessionEndReason;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JulyLeaveReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reconcile_approves_pending_creates_missing_and_matches_matrix(): void
    {
        $ravi = $this->makeUser('Ravi Owner', RolePermissionSeeder::ROLE_SUPERADMIN, 'info@radiumbox.com');
        $shipra = $this->makeUser('Shipra', [RolePermissionSeeder::ROLE_ADMIN, RolePermissionSeeder::ROLE_OPERATIONS_ADMIN], 'shipra@radiumbox.com');
        $avinash = $this->makeUser('Avinash Jha', RolePermissionSeeder::ROLE_ADMIN);
        $sumit = $this->makeUser('Sumit Kumar', RolePermissionSeeder::ROLE_AGENT);
        $this->schedule($sumit);
        $this->schedule($avinash, from: '2026-07-01');
        $this->schedule($shipra, from: '2026-07-01');

        LeaveRequest::query()->create([
            'user_id' => $avinash->id,
            'start_date' => '2026-07-14',
            'end_date' => '2026-07-14',
            'reason' => 'For Personal reason',
            'duration' => LeaveDuration::FullDay,
            'status' => LeaveRequestStatus::Pending,
        ]);

        LeaveRequest::query()->create([
            'user_id' => $shipra->id,
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-14',
            'reason' => 'Personal Work',
            'duration' => LeaveDuration::FullDay,
            'status' => LeaveRequestStatus::Pending,
        ]);

        // Sessions so Present would otherwise stick without leave.
        $this->seedClosedSession($avinash, '2026-07-14');
        $this->seedClosedSession($shipra, '2026-07-13');
        $this->seedClosedSession($sumit, '2026-07-21');
        $this->seedClosedSession($sumit, '2026-07-23');

        // Minimal users required by command name resolution for all needles.
        foreach ([
            'Dileep Sen' => RolePermissionSeeder::ROLE_ADMIN,
            'Sushant Shetty' => RolePermissionSeeder::ROLE_AGENT,
            'Shubhanshi Rathore' => RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST,
            'Abhinav Jha' => RolePermissionSeeder::ROLE_AGENT,
            'Shashank' => RolePermissionSeeder::ROLE_EMPLOYEE,
            'Gaurav Kumar' => RolePermissionSeeder::ROLE_AGENT,
            'Riya' => RolePermissionSeeder::ROLE_EMPLOYEE,
        ] as $name => $role) {
            $u = $this->makeUser($name, $role);
            $this->schedule($u);
        }

        $this->artisan('workforce:july-leave-reconcile', ['--force' => true])
            ->assertSuccessful();

        $this->assertTrue(
            LeaveRequest::query()
                ->where('user_id', $avinash->id)
                ->where('status', LeaveRequestStatus::Approved)
                ->whereDate('start_date', '2026-07-14')
                ->whereDate('end_date', '2026-07-14')
                ->exists(),
        );

        $this->assertTrue(
            LeaveRequest::query()
                ->where('user_id', $shipra->id)
                ->where('status', LeaveRequestStatus::Approved)
                ->whereDate('start_date', '2026-07-13')
                ->whereDate('end_date', '2026-07-13')
                ->exists(),
        );

        $this->assertFalse(
            LeaveRequest::query()
                ->where('user_id', $shipra->id)
                ->where('status', LeaveRequestStatus::Approved)
                ->whereDate('end_date', '2026-07-14')
                ->exists(),
        );

        $this->assertTrue(
            LeaveRequest::query()
                ->where('user_id', $sumit->id)
                ->where('status', LeaveRequestStatus::Approved)
                ->where('duration', LeaveDuration::HalfDay)
                ->whereDate('start_date', '2026-07-21')
                ->exists(),
        );

        $matrix = app(MonthlyAttendanceMatrixService::class)->build(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31 23:59:59', 'Asia/Kolkata'),
        );
        $sumitRow = collect($matrix->members)->firstWhere('userId', $sumit->id);
        $this->assertNotNull($sumitRow);
        $this->assertSame(
            AttendanceMatrixCellKind::HalfDay,
            $sumitRow->cells['2026-07-21']->kind,
        );
        $this->assertSame(
            AttendanceMatrixCellKind::Leave,
            $sumitRow->cells['2026-07-23']->kind,
        );
    }

    private function makeUser(string $name, string|array $roles, ?string $email = null): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email ?? strtolower(str_replace(' ', '.', $name)).'@example.test',
            'is_active' => true,
        ]);
        foreach ((array) $roles as $role) {
            $user->assignRole($role);
        }

        return $user->fresh(['roles']);
    }

    private function schedule(User $user, string $from = '2000-01-01'): void
    {
        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'weekly_off_days' => [0],
            'effective_from' => $from,
        ]);
    }

    private function seedClosedSession(User $user, string $date): void
    {
        WorkSession::query()->create([
            'user_id' => $user->id,
            'work_date' => $date,
            'login_at' => Carbon::parse($date.' 09:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse($date.' 13:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 4 * 3600,
            'active_duration_seconds' => 4 * 3600,
            'on_time_login' => true,
        ]);
    }
}
