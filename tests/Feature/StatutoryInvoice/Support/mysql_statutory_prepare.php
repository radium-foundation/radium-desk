<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inventory\Support\InventoryPosMysqlGate;

require dirname(__DIR__, 2).'/Inventory/Support/mysql_inventory_pos_bootstrap.php';

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

$actor = User::factory()->create([
    'name' => 'Statutory InnoDB Actor',
    'email' => 'statutory-innodb@radium.local',
    'is_active' => true,
]);
$actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

echo json_encode([
    'actor_id' => $actor->id,
], JSON_THROW_ON_ERROR);
