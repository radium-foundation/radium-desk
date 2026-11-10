<?php

use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\FinanceMasterDataSeeder;
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

$processlist = DB::select(
    'select id from information_schema.processlist where db = ? and id <> connection_id()',
    [HardwareFulfilmentMysqlGate::DATABASE]
);
foreach ($processlist as $row) {
    $id = is_object($row) ? (int) ($row->id ?? 0) : 0;
    if ($id > 0) {
        DB::statement('KILL '.(string) $id);
    }
}

$wipe = Artisan::call('db:wipe', ['--force' => true, '--drop-views' => true]);
if ($wipe !== 0) {
    fwrite(STDERR, "db:wipe failed:\n".Artisan::output());
    exit(2);
}

$migrate = Artisan::call('migrate', ['--force' => true]);
if ($migrate !== 0) {
    fwrite(STDERR, "migrate failed:\n".Artisan::output());
    exit(2);
}

app()->make(RolePermissionSeeder::class)->run();
app()->make(FinanceMasterDataSeeder::class)->run();

$actor = User::factory()->create([
    'name' => 'InnoDB HW Actor',
    'email' => 'innodb-hw-actor@radium.local',
    'is_active' => true,
]);
$actor->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

$branch = InventoryBranch::query()->create([
    'code' => 'DELHI-RETAIL',
    'name' => 'Delhi retail InnoDB',
    'is_active' => true,
]);
InventoryUserBranch::query()->create(['user_id' => $actor->id, 'branch_id' => $branch->id]);

$scanner = InventoryProduct::query()->create([
    'sku' => 'INNODB-HW-SCAN',
    'name' => 'InnoDB HW scanner',
    'hsn_code' => '84716050',
    'gst_percentage' => 18,
    'unit_price' => 2549,
    'is_serialized' => true,
    'is_active' => true,
]);
$cable = InventoryProduct::query()->create([
    'sku' => 'INNODB-HW-CABLE',
    'name' => 'InnoDB HW cable',
    'hsn_code' => '85444299',
    'gst_percentage' => 18,
    'unit_price' => 299,
    'is_serialized' => false,
    'is_active' => true,
]);

ChannelSkuMap::query()->create([
    'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
    'model_id' => 946,
    'inventory_product_id' => $scanner->id,
    'catalog_sku' => 'MFS110',
    'channel_sku' => '946',
]);
ChannelSkuMap::query()->create([
    'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
    'model_id' => 1409,
    'inventory_product_id' => $cable->id,
    'catalog_sku' => 'PMTMFSCCBL',
    'channel_sku' => '1409',
]);

$stock = app(InventoryStockService::class);
$stock->stockInSerialized($scanner, $branch, [
    'INNODB-HW-SAME-1',
    'INNODB-HW-IND-A',
    'INNODB-HW-IND-B',
], $actor);
$stock->stockInQuantity($cable, $branch, 3, $actor);

$workflow = app(HardwareFulfilmentWorkflowService::class);
$ingest = app(ChannelIngestService::class);

/**
 * @param  list<array{model_id: int, qty: int}>  $lines
 */
$ingestOrder = static function (string $sourceId, array $lines) use ($ingest, $workflow): HardwareFulfilment {
    $payloadLines = [];
    foreach ($lines as $line) {
        $modelId = $line['model_id'];
        $qty = $line['qty'];
        $unit = $modelId === 1409 ? 299 : 2549;
        $taxable = round(($unit / 1.18) * $qty, 2);
        $tax = round($taxable * 0.18, 2);
        $lineTotal = round($taxable + $tax, 2);
        $payloadLines[] = [
            'description' => $modelId === 1409 ? 'Biometric Replacement Cable' : 'MFS110',
            'sku' => (string) $modelId,
            'qty' => $qty,
            'unit_price' => $unit,
            'hsn_sac' => $modelId === 1409 ? '85444299' : '84716050',
            'gst_percentage' => 18,
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'line_total' => $lineTotal,
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => $modelId,
        ];
    }

    $payload = [
        'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
        'source_type' => 'commerce_order',
        'source_id' => $sourceId,
        'source_order_id' => $sourceId,
        'payment_status' => 'paid',
        'payment_provider' => 'cashfree',
        'payment_reference' => 'pay_'.$sourceId,
        'currency' => 'INR',
        'customer' => [
            'name' => 'Mixed Buyer',
            'phone' => '90000'.substr(md5($sourceId), 0, 5),
        ],
        'seller_gstin' => '07AAICP1128M1Z9',
        'place_of_supply_state' => 'Delhi',
        'lines' => $payloadLines,
    ];

    $ingest->ingest($payload, StatutoryInvoiceChannel::RadiumBoxCom);
    $fulfilment = HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
    $workflow->transition($fulfilment->fresh(['commerceOrder.items']), HardwareFulfilmentState::ReadyForFulfilment);

    return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
};

$mixedA = $ingestOrder('RBP-INNODB-MIX-A', [['model_id' => 946, 'qty' => 1], ['model_id' => 1409, 'qty' => 1]]);
$mixedB = $ingestOrder('RBP-INNODB-MIX-B', [['model_id' => 946, 'qty' => 1], ['model_id' => 1409, 'qty' => 1]]);
$mixedRetry = $ingestOrder('RBP-INNODB-MIX-IDEM', [['model_id' => 946, 'qty' => 1], ['model_id' => 1409, 'qty' => 1]]);
$cableC = $ingestOrder('RBP-INNODB-CABLE-C', [['model_id' => 1409, 'qty' => 2]]);
$cableD = $ingestOrder('RBP-INNODB-CABLE-D', [['model_id' => 1409, 'qty' => 2]]);
$rollback = $ingestOrder('RBP-INNODB-ROLLBACK', [['model_id' => 946, 'qty' => 2]]);
$scannerOnlyA = $ingestOrder('RBP-INNODB-SCAN-A', [['model_id' => 946, 'qty' => 1]]);
$scannerOnlyB = $ingestOrder('RBP-INNODB-SCAN-B', [['model_id' => 946, 'qty' => 1]]);

$itemId = static function (HardwareFulfilment $fulfilment, int $modelId): int {
    return (int) $fulfilment->commerceOrder->items->firstWhere('model_id', $modelId)->id;
};

echo json_encode([
    'actor_id' => $actor->id,
    'branch_id' => $branch->id,
    'scanner_product_id' => $scanner->id,
    'cable_product_id' => $cable->id,
    'contended_serial' => 'INNODB-HW-SAME-1',
    'independent_serial_a' => 'INNODB-HW-IND-A',
    'independent_serial_b' => 'INNODB-HW-IND-B',
    'fulfilment_mixed_a_id' => $mixedA->id,
    'fulfilment_mixed_b_id' => $mixedB->id,
    'fulfilment_mixed_idem_id' => $mixedRetry->id,
    'fulfilment_cable_c_id' => $cableC->id,
    'fulfilment_cable_d_id' => $cableD->id,
    'fulfilment_rollback_id' => $rollback->id,
    'fulfilment_scan_a_id' => $scannerOnlyA->id,
    'fulfilment_scan_b_id' => $scannerOnlyB->id,
    'mixed_a_scanner_item_id' => $itemId($mixedA, 946),
    'mixed_b_scanner_item_id' => $itemId($mixedB, 946),
    'mixed_idem_scanner_item_id' => $itemId($mixedRetry, 946),
    'rollback_scanner_item_id' => $itemId($rollback, 946),
    'scan_a_item_id' => $itemId($scannerOnlyA, 946),
    'scan_b_item_id' => $itemId($scannerOnlyB, 946),
], JSON_THROW_ON_ERROR).PHP_EOL;
