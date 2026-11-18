<?php

use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Enums\ApprovedRefundMethod;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\BusinessHoldService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Refunds\Support\RefundCompletionMysqlGate;
use Tests\Feature\Refunds\Support\RefundCompletionMysqlPrepareLock;

require __DIR__.'/mysql_refund_completion_bootstrap.php';

$options = getopt('', ['method::', 'suffix::']);
$method = strtolower((string) ($options['method'] ?? 'wallet'));
$suffix = (string) ($options['suffix'] ?? bin2hex(random_bytes(4)));

$lockHandle = RefundCompletionMysqlPrepareLock::acquire();
register_shutdown_function(
    static function () use ($lockHandle): void {
        RefundCompletionMysqlPrepareLock::release($lockHandle);
    }
);

$credentials = refund_completion_mysql_credentials_from_env();
$deskReference = 'REF-CONC-'.strtoupper($suffix);
refund_completion_mysql_boot_app($credentials, $deskReference);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! RefundCompletionMysqlGate::isAllowedDatabase((string) $connected->d)) {
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
app()->make(FinanceMasterDataSeeder::class)->run();

$ops = User::factory()->create([
    'name' => 'Refund Conc Ops',
    'email' => 'refund-conc-ops-'.$suffix.'@example.com',
    'is_active' => true,
]);
$ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

$approvedMethod = $method === 'bank'
    ? ApprovedRefundMethod::BankTransfer
    : ApprovedRefundMethod::Wallet;

$customerId = (string) Str::uuid();
$cwId = (string) Str::uuid();
CentralWallet::query()->create(['id' => $cwId, 'status' => 'active']);
CentralCustomer::query()->create([
    'id' => $customerId,
    'central_wallet_id' => $cwId,
    'status' => 'active',
]);

$orderDigits = str_pad((string) ((int) hexdec(substr($suffix, 0, 4)) % 900000 + 100000), 6, '0', STR_PAD_LEFT);
$order = Order::query()->create([
    'order_id' => 'RD'.$orderDigits,
    'customer_id' => $customerId,
    'serial_number' => 'SN-CONC-'.$suffix,
    'product_name' => 'Radium Device',
    'device_model' => 'Model X',
    'status' => 'active',
    'payment_amount' => '617.00',
    'customer_email' => 'conc-'.$suffix.'@example.com',
    'customer_phone' => '9000000001',
    'created_by' => $ops->id,
]);

$incident = Incident::query()->create([
    'order_id' => $order->id,
    'reference_no' => 'SC-CONC-'.$suffix,
    'category' => 'Hardware',
    'source' => 'internal',
    'title' => 'Refund completion concurrency',
    'description' => 'Synthetic concurrent completion target.',
    'status' => IncidentStatus::Open,
    'created_by' => $ops->id,
]);

$refund = RefundRequest::query()->create([
    'order_id' => $order->id,
    'incident_id' => $incident->id,
    'reference_no' => $deskReference,
    'amount' => '617.00',
    'refund_amount' => '617.00',
    'reason' => 'MariaDB concurrent completion regression.',
    'status' => RefundStatus::PendingExecution,
    'approved_refund_method' => $approvedMethod,
    'requested_by' => $ops->id,
    'reviewed_by' => $ops->id,
    'reviewed_at' => now(),
    'communication_channels' => [],
]);

app(BusinessHoldService::class)->activateRefundHold($incident, $refund, $ops);

echo json_encode([
    'desk_reference' => $deskReference,
    'suffix' => $suffix,
    'method' => $method,
    'ops_user_id' => $ops->id,
    'refund_id' => $refund->id,
    'incident_id' => $incident->id,
    'order_id' => $order->id,
], JSON_THROW_ON_ERROR);
