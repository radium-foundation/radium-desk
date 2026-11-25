<?php

use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\RefundRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Refunds\Support\WalletAutoApprovalMysqlGate;
use Tests\Feature\Refunds\Support\WalletAutoApprovalMysqlPrepareLock;

require __DIR__.'/mysql_wallet_auto_approval_bootstrap.php';

$options = getopt('', ['scenario::', 'suffix::']);
$scenario = strtolower((string) ($options['scenario'] ?? 'dual_create'));
$suffix = (string) ($options['suffix'] ?? bin2hex(random_bytes(4)));

$lockHandle = WalletAutoApprovalMysqlPrepareLock::acquire();
if ($lockHandle === null) {
    fwrite(STDERR, "Could not acquire prepare lock.\n");
    exit(2);
}
register_shutdown_function(static function () use ($lockHandle): void {
    WalletAutoApprovalMysqlPrepareLock::release($lockHandle);
});

$credentials = wallet_auto_approval_mysql_credentials_from_env();
wallet_auto_approval_mysql_boot_app($credentials);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! WalletAutoApprovalMysqlGate::isAllowedDatabase((string) $connected->d)) {
    fwrite(STDERR, "Prepare refused: unexpected database.\n");
    exit(2);
}

if (Artisan::call('db:wipe', ['--force' => true, '--drop-views' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}

app()->make(RolePermissionSeeder::class)->run();

$agent = User::factory()->create(['is_active' => true]);
$agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

$ops = User::factory()->create(['is_active' => true, 'email' => 'ops-waa-'.$suffix.'@example.com']);
$ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

/**
 * @return array{order_id: int, business_order_id: string}
 */
function wallet_auto_approval_seed_order(User $agent, string $businessOrderId, string $amount = '1500.00'): array
{
    $customerId = (string) Str::uuid();
    $cwId = (string) Str::uuid();
    CentralWallet::query()->create(['id' => $cwId, 'status' => 'active']);
    CentralCustomer::query()->create([
        'id' => $customerId,
        'central_wallet_id' => $cwId,
        'status' => 'active',
    ]);

    $order = Order::query()->create([
        'order_id' => $businessOrderId,
        'customer_id' => $customerId,
        'serial_number' => 'SN-'.$businessOrderId,
        'product_name' => 'Device',
        'device_model' => 'Model',
        'status' => 'active',
        'payment_amount' => $amount,
        'customer_email' => 'waa-'.$businessOrderId.'@example.com',
        'created_by' => $agent->id,
    ]);

    return ['order_id' => $order->id, 'business_order_id' => $businessOrderId];
}

$context = [
    'scenario' => $scenario,
    'suffix' => $suffix,
    'agent_id' => $agent->id,
    'ops_user_id' => $ops->id,
];

if ($scenario === 'dual_create' || $scenario === 'rollback_probe') {
    $seed = wallet_auto_approval_seed_order($agent, 'RD'.strtoupper(substr($suffix, 0, 6)), '1500.00');
    $context = array_merge($context, $seed);
    $context['worker0'] = ['action' => 'create'];
    $context['worker1'] = ['action' => 'create'];
} elseif ($scenario === 'independent_dual_create') {
    $seedA = wallet_auto_approval_seed_order($agent, 'RD'.strtoupper(substr($suffix, 0, 3)).'A', '1200.00');
    $seedB = wallet_auto_approval_seed_order($agent, 'RD'.strtoupper(substr($suffix, 0, 3)).'B', '1100.00');
    $context = array_merge($context, [
        'order_a_id' => $seedA['order_id'],
        'order_b_id' => $seedB['order_id'],
        'worker0' => ['action' => 'create', 'order_id' => $seedA['order_id']],
        'worker1' => ['action' => 'create', 'order_id' => $seedB['order_id']],
    ]);
} elseif ($scenario === 'manual_approve_race') {
    $seed = wallet_auto_approval_seed_order($agent, 'RD'.strtoupper(substr($suffix, 0, 6)), '1300.00');
    config(['refunds.wallet_auto_approval.enabled' => false]);

    $payload = [
        'order_id' => $seed['order_id'],
        'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
        'reason' => 'Pending refund for manual approve race.',
        'remarks' => 'Manual race probe.',
        'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
    ];

    $pending = app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));
    $thisRefundId = $pending->id;
    config(['refunds.wallet_auto_approval.enabled' => true]);

    $context = array_merge($context, $seed, [
        'refund_id' => $thisRefundId,
        'worker0' => ['action' => 'approve'],
        'worker1' => ['action' => 'approve'],
    ]);
} elseif ($scenario === 'auto_create_vs_manual_approve') {
    $seed = wallet_auto_approval_seed_order($agent, 'RD'.strtoupper(substr($suffix, 0, 6)), '1400.00');
    config(['refunds.wallet_auto_approval.enabled' => false]);

    $payload = [
        'order_id' => $seed['order_id'],
        'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
        'reason' => 'Pending refund before auto/manual race.',
        'remarks' => 'Auto create vs manual approve.',
        'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
    ];

    $pending = app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));
    config(['refunds.wallet_auto_approval.enabled' => true]);

    $context = array_merge($context, $seed, [
        'refund_id' => $pending->id,
        'worker0' => ['action' => 'create'],
        'worker1' => ['action' => 'approve'],
    ]);
} elseif ($scenario === 'auto_promote_vs_manual_approve') {
    $seed = wallet_auto_approval_seed_order($agent, 'RD'.strtoupper(substr($suffix, 0, 6)), '1350.00');
    config(['refunds.wallet_auto_approval.enabled' => false]);

    $payload = [
        'order_id' => $seed['order_id'],
        'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
        'reason' => 'Pending refund for auto promotion race.',
        'remarks' => 'Auto promotion vs manual approve.',
        'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
    ];

    $pending = app(RefundRequestService::class)->create($agent, $payload, Request::create('/'));
    config(['refunds.wallet_auto_approval.enabled' => true]);

    $context = array_merge($context, $seed, [
        'refund_id' => $pending->id,
        'worker0' => ['action' => 'auto_promote'],
        'worker1' => ['action' => 'approve'],
    ]);
} else {
    fwrite(STDERR, "Unknown scenario [{$scenario}].\n");
    exit(2);
}

echo json_encode($context, JSON_THROW_ON_ERROR);
