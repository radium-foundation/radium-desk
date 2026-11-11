<?php

declare(strict_types=1);
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Disposable sqlite bootstrap for P-11-21 mixed serial allocation browser QA (local only).
 */
$root = dirname(__DIR__, 4);
chdir($root);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dbFile = $root.'/database/p11-21-mixed-serial-browser.sqlite';
if (is_file($dbFile)) {
    unlink($dbFile);
}
touch($dbFile);

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $dbFile,
    'channel_ingest.secrets.radiumbox_com' => 'p11-21-browser-secret',
    'channel_ingest.auto_issue_invoice' => false,
    'hardware_fulfilment.correlate_cashfree' => false,
    'statutory_invoices.post_finance_journals' => false,
    'statutory_invoices.auto_issue_on_pos_complete' => false,
    'statutory_invoices.worker_may_mint' => false,
    'statutory_invoices.einvoice.provider' => 'none',
    'shipping.enabled' => false,
]);

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

$actor = User::factory()->create([
    'email' => 'p1121-hw-qa@disposable.local',
    'password' => bcrypt('password'),
    'is_active' => true,
]);
$actor->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

$scanner = InventoryProduct::query()->create([
    'sku' => 'RBMFS110MIX',
    'name' => 'MFS110 scanner',
    'hsn_code' => '84716050',
    'gst_percentage' => 18,
    'unit_price' => 2549,
    'is_serialized' => true,
    'is_active' => true,
]);
$cable = InventoryProduct::query()->create([
    'sku' => 'RBMFSTYPEC',
    'name' => 'Type-C cable',
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

$delhi = InventoryBranch::query()->firstOrCreate(
    ['code' => 'DELHI-RETAIL'],
    ['name' => 'Delhi Retail', 'is_active' => true],
);
$mumbai = InventoryBranch::query()->firstOrCreate(
    ['code' => 'MUMBAI-RETAIL'],
    ['name' => 'Mumbai Retail', 'is_active' => true],
);
InventoryUserBranch::query()->firstOrCreate(['user_id' => $actor->id, 'branch_id' => $delhi->id]);

$stock = app(InventoryStockService::class);
$stock->stockInSerialized($scanner, $delhi, ['SN-P21-OK', 'SN-P21-DUP'], $actor);
$stock->stockInSerialized($scanner, $mumbai, ['SN-P21-WRONG-BR'], $actor);
$stock->stockInQuantity($cable, $delhi, 10, $actor);

$auth = new ChannelIngestAuthenticator;
$workflow = app(HardwareFulfilmentWorkflowService::class);

$ingest = function (string $sourceId, array $lines) use ($auth, $workflow, $delhi): HardwareFulfilment {
    $payloadLines = [];
    foreach ($lines as $line) {
        $modelId = $line['model_id'];
        $qty = $line['qty'];
        $unit = $modelId === 1409 ? 299 : 2549;
        $taxable = round(($unit / 1.18) * $qty, 2);
        $tax = round($taxable * 0.18, 2);
        $payloadLines[] = [
            'description' => $modelId === 1409 ? 'Biometric Replacement Cable' : 'MFS110',
            'sku' => (string) $modelId,
            'qty' => $qty,
            'unit_price' => $unit,
            'hsn_sac' => $modelId === 1409 ? '85444299' : '84716050',
            'gst_percentage' => 18,
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'line_total' => round($taxable + $tax, 2),
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
        'customer' => ['name' => 'P11-21 QA', 'phone' => '9000001121'],
        'seller_gstin' => '07AAICP1128M1Z9',
        'place_of_supply_state' => 'Delhi',
        'lines' => $payloadLines,
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $response = app()->handle(Request::create('/api/v1/channel-orders', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
        'HTTP_X_DESK_TIMESTAMP' => $timestamp,
        'HTTP_X_DESK_SIGNATURE' => $auth->signature($timestamp, $body, 'p11-21-browser-secret'),
    ], $body));
    if ($response->getStatusCode() >= 400) {
        throw new RuntimeException("Ingest failed for {$sourceId}: ".$response->getStatusCode());
    }
    $fulfilment = HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
    $workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
    $fulfilment->forceFill(['fulfilment_branch_id' => $delhi->id])->save();

    return $fulfilment->fresh() ?? $fulfilment;
};

$cableOnly = $ingest('RBP-P21-CABLE', [['model_id' => 1409, 'qty' => 1]]);
$scannerOnly = $ingest('RBP-P21-SCAN', [['model_id' => 946, 'qty' => 1]]);
$mixed = $ingest('RBP-P21-MIX', [['model_id' => 946, 'qty' => 1], ['model_id' => 1409, 'qty' => 1]]);
$scannerSecond = $ingest('RBP-P21-SCAN2', [['model_id' => 946, 'qty' => 1]]);

$evidenceDir = $root.'/storage/app/private/qa-evidence/p11-21';
if (! is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0755, true);
}

$credentials = [
    'email' => 'p1121-hw-qa@disposable.local',
    'password' => 'password',
    'base_url' => 'http://127.0.0.1:8771',
    'fulfilments' => [
        'cable_only' => $cableOnly->id,
        'scanner_only' => $scannerOnly->id,
        'mixed' => $mixed->id,
        'scanner_second' => $scannerSecond->id,
    ],
];
file_put_contents($evidenceDir.'/browser-credentials.json', json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($evidenceDir.'/seed-meta.json', json_encode([
    'prompt' => 'P-11-21',
    'seeded_at' => now()->toIso8601String(),
    'orders' => ['RBP-P21-CABLE', 'RBP-P21-SCAN', 'RBP-P21-MIX', 'RBP-P21-SCAN2'],
], JSON_PRETTY_PRINT)."\n");

echo "Bootstrap OK\n";
