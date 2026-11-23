<?php

use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentStatus;
use App\Enums\PreShipmentCancellationServiceCaseSyncStatus;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\Incident;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Services\PreShipmentCancellation\PreShipmentCancellationIncidentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\HardwareFulfilment\Support\HardwareFulfilmentMysqlPrepareLock;

require __DIR__.'/mysql_preship_bootstrap.php';

$lockHandle = HardwareFulfilmentMysqlPrepareLock::acquire();
register_shutdown_function(
    static function () use ($lockHandle): void {
        HardwareFulfilmentMysqlPrepareLock::release($lockHandle);
    }
);

$credentials = preship_mysql_credentials_from_env();
preship_mysql_boot_app($credentials);

if (Artisan::call('db:wipe', ['--force' => true, '--drop-views' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}

app()->make(RolePermissionSeeder::class)->run();

$sourceId = 'RBP-PSC-INC-'.bin2hex(random_bytes(3));
$actor = User::query()->create([
    'name' => 'PSC Incident Retry Actor',
    'email' => 'psc-incident-'.$sourceId.'@example.test',
    'password' => bcrypt('secret'),
]);

$order = Order::query()->create([
    'order_id' => $sourceId,
    'product_name' => 'Device',
    'device_model' => 'M1',
    'status' => 'active',
    'payment_amount' => 1000,
    'cashfree_payment_id' => 'cf-'.$sourceId,
    'created_by' => $actor->id,
]);

CommerceOrder::query()->create([
    'order_no' => 'CO-'.$sourceId,
    'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
    'source_type' => 'commerce_order',
    'source_id' => $order->order_id,
    'idempotency_key' => 'psc-inc-'.$sourceId,
    'payload_hash' => 'hash',
    'status' => CommerceOrderStatus::InvoicePending,
    'invoice_eligible' => true,
    'payment_status' => 'paid',
    'currency' => 'INR',
    'order_value' => 1000,
    'received_at' => now(),
    'support_order_id' => $order->id,
]);

$incident = Incident::query()->create([
    'order_id' => $order->id,
    'reference_no' => 'INC-'.$sourceId,
    'category' => 'Hardware',
    'source' => 'internal',
    'title' => 'Hardware',
    'description' => 'InnoDB incident retry',
    'status' => IncidentStatus::Open,
    'created_by' => $actor->id,
]);

$case = PreShipmentCancellationRequest::query()->create([
    'reference_no' => 'PSC-'.$sourceId,
    'order_id' => $order->id,
    'source_id' => $order->order_id,
    'order_family' => 'rbp',
    'status' => PreShipmentCancellationStatus::HeldServiceCase,
    'cancellation_reason' => 'Incident concurrency',
    'submitted_by_user_id' => $actor->id,
    'order_total_amount' => 1000,
    'wallet_tender_amount' => 0,
    'external_tender_amount' => 1000,
    'already_refunded_amount' => 0,
    'maximum_refundable_amount' => 1000,
    'requested_refund_amount' => 1000,
    'split_tender' => false,
    'execution_hold' => false,
    'idempotency_key' => 'psc-case-'.$sourceId,
    'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::Failed,
    'committed_at' => now(),
]);

fwrite(STDOUT, json_encode([
    'actor_id' => $actor->id,
    'order_id' => $order->id,
    'incident_id' => $incident->id,
    'case_id' => $case->id,
    'audit_event' => PreShipmentCancellationIncidentService::AUDIT_HARDWARE_PRESHIP_CANCELLED,
], JSON_THROW_ON_ERROR));
