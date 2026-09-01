<?php

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inventory\Support\InventoryPosMysqlGate;

require __DIR__.'/mysql_inventory_pos_bootstrap.php';

$credentials = inventory_pos_mysql_credentials_from_env();
inventory_pos_mysql_boot_app($credentials);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! InventoryPosMysqlGate::isAllowedDatabase((string) $connected->d)) {
    fwrite(STDERR, "Prepare refused: unexpected database.\n");
    exit(2);
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
    'name' => 'InnoDB QA Actor',
    'email' => 'innodb-qa-actor@radium.local',
    'is_active' => true,
]);
$actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

$branch = InventoryBranch::query()->create([
    'code' => 'INNO',
    'name' => 'InnoDB Test Counter',
    'is_active' => true,
]);

$serialized = InventoryProduct::query()->create([
    'sku' => 'INNODB-SCANNER',
    'name' => 'InnoDB scanner',
    'gst_percentage' => 18,
    'unit_price' => 2500,
    'is_serialized' => true,
    'is_active' => true,
]);

$quantity = InventoryProduct::query()->create([
    'sku' => 'INNODB-OTG',
    'name' => 'InnoDB OTG',
    'gst_percentage' => 18,
    'unit_price' => 50,
    'is_serialized' => false,
    'is_active' => true,
]);

$stock = app(InventoryStockService::class);
$stock->stockInSerialized($serialized, $branch, [
    'INNODB-SAME-1',
    'INNODB-IND-A',
    'INNODB-IND-B',
], $actor);
$stock->stockInQuantity($quantity, $branch, 10, $actor);

echo json_encode([
    'actor_id' => $actor->id,
    'branch_id' => $branch->id,
    'serialized_product_id' => $serialized->id,
    'quantity_product_id' => $quantity->id,
    'contended_serial' => 'INNODB-SAME-1',
    'independent_serial_a' => 'INNODB-IND-A',
    'independent_serial_b' => 'INNODB-IND-B',
], JSON_THROW_ON_ERROR).PHP_EOL;
