<?php

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\InventoryProduct;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\HardwareFulfilment\Support\HardwareFulfilmentMysqlGate;
use Tests\Feature\HardwareFulfilment\Support\HardwareFulfilmentMysqlPrepareLock;

require __DIR__.'/mysql_hardware_fulfilment_bootstrap.php';

$lockHandle = HardwareFulfilmentMysqlPrepareLock::acquire();
register_shutdown_function(
    static function () use ($lockHandle): void {
        HardwareFulfilmentMysqlPrepareLock::release($lockHandle);
    }
);

$credentials = hardware_fulfilment_mysql_credentials_from_env();
$app = hardware_fulfilment_mysql_boot_app($credentials);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! HardwareFulfilmentMysqlGate::isAllowedDatabase((string) $connected->d)) {
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

config([
    'channel_ingest.secrets.radiumbox_com' => 'innodb-rbp-ingest-secret',
    'channel_ingest.auto_issue_invoice' => false,
    'hardware_fulfilment.correlate_cashfree' => false,
]);

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

$sourceId = 'RBP-INNODB-CONC';
$payload = [
    'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
    'source_type' => 'commerce_order',
    'source_id' => $sourceId,
    'source_order_id' => $sourceId,
    'payment_status' => 'paid',
    'payment_provider' => 'cashfree',
    'payment_reference' => 'pay_'.$sourceId,
    'currency' => 'INR',
    'ordered_at' => '2026-09-06T10:00:00+05:30',
    'customer' => [
        'name' => 'InnoDB RBP Buyer',
        'phone' => '9000012345',
    ],
    'seller_gstin' => '07AAICP1128M1Z9',
    'place_of_supply_state' => 'Delhi',
    'lines' => [[
        'description' => 'MFS110',
        'sku' => '946',
        'qty' => 1,
        'unit_price' => 2499,
        'hsn_sac' => '84716050',
        'gst_percentage' => 18,
        'taxable_value' => 2117.80,
        'tax_total' => 381.20,
        'line_total' => 2499,
        'shipping_line_kind' => 'physical_merchandise',
        'requires_shipping' => true,
        'model_id' => 946,
    ]],
];

echo json_encode([
    'source_id' => $sourceId,
    'ingest_payload' => $payload,
], JSON_THROW_ON_ERROR).PHP_EOL;