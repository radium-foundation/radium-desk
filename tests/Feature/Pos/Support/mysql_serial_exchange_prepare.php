<?php

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inventory\Support\InventoryPosMysqlGate;

require __DIR__.'/../../Inventory/Support/mysql_inventory_pos_bootstrap.php';

$credentials = inventory_pos_mysql_credentials_from_env();
inventory_pos_mysql_boot_app($credentials);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! InventoryPosMysqlGate::isAllowedDatabase((string) $connected->d)) {
    fwrite(STDERR, "Prepare refused: unexpected database.\n");
    exit(2);
}

if (Artisan::call('db:wipe', ['--force' => true, '--drop-views' => true]) !== 0) {
    fwrite(STDERR, "db:wipe failed:\n".Artisan::output());
    exit(2);
}

if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    fwrite(STDERR, "migrate failed:\n".Artisan::output());
    exit(2);
}

app()->make(RolePermissionSeeder::class)->run();
app()->make(FinanceMasterDataSeeder::class)->run();

$actor = User::factory()->create([
    'name' => 'Serial Exchange InnoDB Actor',
    'email' => 'serial-exchange-innodb@radium.local',
    'is_active' => true,
]);
$actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

$branch = InventoryBranch::query()->create([
    'code' => 'INNO-EXCH',
    'name' => 'InnoDB Exchange Counter',
    'is_active' => true,
]);
InventoryUserBranch::query()->create([
    'user_id' => $actor->id,
    'branch_id' => $branch->id,
]);

$product = InventoryProduct::query()->create([
    'sku' => 'INNODB-EXCH-SKU',
    'name' => 'InnoDB exchange device',
    'gst_percentage' => 18,
    'unit_price' => 3200,
    'is_serialized' => true,
    'is_active' => true,
]);

$serialsOnSale = [
    'INNODB-EXCH-ORIG',
    'INNODB-EXCH-ORIG2',
    'INNODB-EXCH-EXTRA1',
    'INNODB-EXCH-EXTRA2',
];
$replacements = [
    'INNODB-EXCH-REPL',
    'INNODB-EXCH-REPL2',
];

$stock = app(InventoryStockService::class);
$stock->stockInSerialized($product, $branch, $serialsOnSale, $actor);
$stock->stockInSerialized($product, $branch, $replacements, $actor);

$sale = app(PosSaleService::class)->completeSale(
    branch: $branch,
    customer: ['name' => 'Exchange Buyer', 'phone' => '9888800001'],
    lines: [['product_id' => $product->id, 'qty' => 4, 'serials' => $serialsOnSale]],
    paymentMethod: 'Cash',
    actor: $actor,
);

echo json_encode([
    'actor_id' => $actor->id,
    'branch_id' => $branch->id,
    'product_id' => $product->id,
    'sale_id' => $sale->id,
    'original_serial' => 'INNODB-EXCH-ORIG',
    'original_serial_2' => 'INNODB-EXCH-ORIG2',
    'replacement_serial' => 'INNODB-EXCH-REPL',
    'replacement_serial_2' => 'INNODB-EXCH-REPL2',
], JSON_THROW_ON_ERROR);
