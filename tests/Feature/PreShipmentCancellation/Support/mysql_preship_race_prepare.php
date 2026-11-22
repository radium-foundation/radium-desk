<?php

use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\Incident;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\HardwareFulfilment\Support\HardwareFulfilmentMysqlPrepareLock;
use Tests\Feature\PreShipmentCancellation\Support\PreShipmentCancellationMariaDbGate;

require __DIR__.'/mysql_preship_bootstrap.php';

$lockHandle = HardwareFulfilmentMysqlPrepareLock::acquire();
register_shutdown_function(
    static function () use ($lockHandle): void {
        HardwareFulfilmentMysqlPrepareLock::release($lockHandle);
    }
);

$credentials = preship_mysql_credentials_from_env();
$app = preship_mysql_boot_app($credentials);

if (Artisan::call('db:wipe', ['--force' => true, '--drop-views' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}

app()->make(RolePermissionSeeder::class)->run();

$product = InventoryProduct::query()->create([
    'sku' => 'RBMFS110L1',
    'name' => 'MFS110',
    'hsn_code' => '84716050',
    'gst_percentage' => 18,
    'unit_price' => 2499,
    'is_serialized' => true,
    'is_active' => true,
]);
ChannelSkuMap::query()->create([
    'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
    'model_id' => 946,
    'inventory_product_id' => $product->id,
    'catalog_sku' => 'RBMFS110L1',
    'channel_sku' => '946',
]);

$sourceId = 'RBP-PRESHIP-RACE-'.bin2hex(random_bytes(3));
$actor = User::query()->create([
    'name' => 'PSC InnoDB Actor',
    'email' => 'psc-innodb-'.$sourceId.'@example.test',
    'password' => bcrypt('secret'),
]);
$actor->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

$order = Order::query()->create([
    'order_id' => $sourceId,
    'product_name' => 'MFS110',
    'status' => 'active',
    'cashfree_payment_id' => 'cf-'.$sourceId,
    'payment_amount' => 2499,
    'created_by' => $actor->id,
    'created_at' => '2026-11-14 12:00:00',
]);

$commerce = CommerceOrder::query()->create([
    'order_no' => 'CO-'.$sourceId,
    'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
    'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
    'source_id' => $sourceId,
    'idempotency_key' => 'psc-race:'.$sourceId,
    'payload_hash' => hash('sha256', $sourceId),
    'status' => CommerceOrderStatus::InvoicePending,
    'invoice_eligible' => true,
    'payment_status' => 'paid',
    'payment_reference' => 'cf-'.$sourceId,
    'currency' => 'INR',
    'order_value' => 2499,
    'received_at' => now()->subHours(2),
    'support_order_id' => $order->id,
    'created_at' => now()->subHours(2),
]);

CommerceOrderItem::query()->create([
    'commerce_order_id' => $commerce->id,
    'line_no' => 1,
    'sku' => '946',
    'shipping_line_kind' => 'physical_merchandise',
    'requires_shipping' => true,
    'model_id' => 946,
    'description' => 'MFS110',
    'qty' => 1,
    'unit_price' => 2499,
    'line_total' => 2499,
]);

Incident::query()->create([
    'order_id' => $order->id,
    'reference_no' => 'INC-'.$sourceId,
    'category' => 'Hardware',
    'source' => 'internal',
    'title' => 'Awaiting serial',
    'description' => 'Race harness',
    'status' => IncidentStatus::Open,
    'created_by' => $actor->id,
]);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! PreShipmentCancellationMariaDbGate::isAllowedDatabase((string) $connected->d)) {
    fwrite(STDERR, "Prepare refused unexpected database.\n");
    exit(2);
}

echo json_encode([
    'source_id' => $sourceId,
    'order_id' => $order->id,
    'commerce_order_id' => $commerce->id,
    'actor_id' => $actor->id,
    'idempotency_key' => 'psc-race-submit:'.$order->id,
], JSON_THROW_ON_ERROR).PHP_EOL;
