<?php

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Workforce\Support\LeavePolicyMysqlPrepareLock;

require __DIR__.'/LeavePolicyMysqlPrepareLock.php';
require __DIR__.'/mysql_leave_policy_bootstrap.php';

$options = getopt('', ['scenario::', 'suffix::']);
$scenario = strtolower((string) ($options['scenario'] ?? 'admin_pair'));
$suffix = (string) ($options['suffix'] ?? bin2hex(random_bytes(4)));

$lockHandle = LeavePolicyMysqlPrepareLock::acquire();
if ($lockHandle === null) {
    fwrite(STDERR, "Could not acquire prepare lock.\n");
    exit(2);
}
register_shutdown_function(static function () use ($lockHandle): void {
    LeavePolicyMysqlPrepareLock::release($lockHandle);
});

$credentials = leave_policy_mysql_credentials_from_env();
leave_policy_mysql_boot_app($credentials);

if (Artisan::call('db:wipe', ['--force' => true, '--drop-views' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}

app()->make(RolePermissionSeeder::class)->run();
Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

config(['workforce.leave_approver.email' => 'leave-authority-'.$suffix.'@radiumbox.com']);
$approver = User::factory()->create([
    'email' => 'leave-authority-'.$suffix.'@radiumbox.com',
    'is_active' => true,
]);
$approver->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

$leaveService = app(LeaveRequestService::class);
$context = [
    'scenario' => $scenario,
    'suffix' => $suffix,
    'approver_id' => $approver->id,
    'leave_approver_email' => config('workforce.leave_approver.email'),
    'day' => '2026-08-07',
];

if ($scenario === 'admin_pair') {
    config([
        'leave_policy.coverage.triad_emails' => [],
        'leave_policy.coverage.admin_pair_emails' => ['dileep-'.$suffix.'@test.com', 'avinash-'.$suffix.'@test.com'],
    ]);

    $dileep = User::factory()->create(['email' => 'dileep-'.$suffix.'@test.com', 'is_active' => true]);
    $avinash = User::factory()->create(['email' => 'avinash-'.$suffix.'@test.com', 'is_active' => true]);
    $dileep->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

    $dileepLeave = $leaveService->submit($dileep, [
        'start_date' => $context['day'],
        'end_date' => $context['day'],
        'reason' => 'Admin leave A',
        'manual_exception' => true,
    ]);
    $avinashLeave = $leaveService->submit($avinash, [
        'start_date' => $context['day'],
        'end_date' => $context['day'],
        'reason' => 'Admin leave B',
        'manual_exception' => true,
    ]);

    $context['worker0'] = ['action' => 'approve', 'leave_id' => $dileepLeave->id];
    $context['worker1'] = ['action' => 'approve', 'leave_id' => $avinashLeave->id];
} elseif ($scenario === 'triad_race') {
    config([
        'leave_policy.coverage.triad_emails' => [
            'shubhanshi-'.$suffix.'@test.com',
            'abhinav-'.$suffix.'@test.com',
            'kanchan-'.$suffix.'@test.com',
        ],
        'leave_policy.coverage.admin_pair_emails' => [],
    ]);

    $shubhanshi = User::factory()->create(['email' => 'shubhanshi-'.$suffix.'@test.com', 'is_active' => true]);
    $abhinav = User::factory()->create(['email' => 'abhinav-'.$suffix.'@test.com', 'is_active' => true]);
    $kanchan = User::factory()->create(['email' => 'kanchan-'.$suffix.'@test.com', 'is_active' => true]);
    foreach ([$shubhanshi, $abhinav, $kanchan] as $user) {
        $user->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
    }

    $approved = $leaveService->submit($shubhanshi, [
        'start_date' => $context['day'],
        'end_date' => $context['day'],
        'reason' => 'Triad one',
    ]);
    $leaveService->approve($approved, $approver, 'Ok');

    $abhinavLeave = $leaveService->submit($abhinav, [
        'start_date' => $context['day'],
        'end_date' => $context['day'],
        'reason' => 'Triad two',
    ]);
    $kanchanLeave = $leaveService->submit($kanchan, [
        'start_date' => $context['day'],
        'end_date' => $context['day'],
        'reason' => 'Triad three',
        'manual_exception' => true,
    ]);

    $context['worker0'] = ['action' => 'approve', 'leave_id' => $abhinavLeave->id];
    $context['worker1'] = ['action' => 'approve', 'leave_id' => $kanchanLeave->id];
} elseif ($scenario === 'duplicate_approve') {
    $agent = User::factory()->create(['email' => 'agent-'.$suffix.'@test.com', 'is_active' => true]);
    $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

    $pending = $leaveService->submit($agent, [
        'start_date' => $context['day'],
        'end_date' => $context['day'],
        'reason' => 'Once only',
    ]);

    $context['worker0'] = ['action' => 'approve', 'leave_id' => $pending->id];
    $context['worker1'] = ['action' => 'approve', 'leave_id' => $pending->id];
} elseif ($scenario === 'monthly_cap_race') {
    $agent = User::factory()->create(['email' => 'agent-'.$suffix.'@test.com', 'is_active' => true]);
    $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

    $pendingLeaves = [];
    foreach (['2026-08-05', '2026-08-12', '2026-08-19'] as $day) {
        $pendingLeaves[] = $leaveService->submit($agent, [
            'start_date' => $day,
            'end_date' => $day,
            'reason' => 'Exception',
            'manual_exception' => true,
        ]);
    }

    $leaveService->approve($pendingLeaves[0], $approver, 'Counted one');

    $context['worker0'] = ['action' => 'approve', 'leave_id' => $pendingLeaves[1]->id, 'override' => false];
    $context['worker1'] = ['action' => 'approve', 'leave_id' => $pendingLeaves[2]->id, 'override' => false];
} else {
    fwrite(STDERR, "Unknown scenario [{$scenario}].\n");
    exit(2);
}

$approvedOnDay = (int) LeaveRequest::query()
    ->whereIn('status', [LeaveRequestStatus::Approved->value, LeaveRequestStatus::Pending->value])
    ->whereDate('start_date', $context['day'])
    ->count();

$context['pending_before'] = $approvedOnDay;
$context['leave_policy_coverage'] = [
    'triad_emails' => config('leave_policy.coverage.triad_emails', []),
    'admin_pair_emails' => config('leave_policy.coverage.admin_pair_emails', []),
];

echo json_encode($context, JSON_THROW_ON_ERROR);
