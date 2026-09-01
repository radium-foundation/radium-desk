<?php

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

$database = (string) (getenv('DB_DATABASE') ?: '');
$connection = (string) (getenv('DB_CONNECTION') ?: '');
$basename = basename($database);
$appEnv = (string) (getenv('APP_ENV') ?: 'local');

if ($connection !== 'sqlite' || $basename !== 'inventory-pos-browser-qa.sqlite') {
    fwrite(STDERR, "Refusing browser QA seed: sqlite file must be inventory-pos-browser-qa.sqlite.\n");
    exit(2);
}

if (! in_array($appEnv, ['local', 'testing', 'development'], true)) {
    fwrite(STDERR, "Refusing browser QA seed outside local/testing.\n");
    exit(2);
}

require dirname(__DIR__, 4).'/vendor/autoload.php';

$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$databaseReal = realpath($database) ?: $database;
$databaseDir = realpath(dirname($database)) ?: dirname($database);
$allowedDir = realpath(database_path()) ?: database_path();
if ($databaseDir !== $allowedDir && ! str_starts_with($databaseReal, $allowedDir.'/')) {
    fwrite(STDERR, "Refusing browser QA seed: sqlite path is not under this repo database/ directory.\n");
    exit(2);
}

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $database,
]);

Artisan::call('migrate:fresh', ['--force' => true]);
app()->make(RolePermissionSeeder::class)->run();
app()->make(FinanceMasterDataSeeder::class)->run();

$admin = User::factory()->create([
    'name' => 'QA Admin',
    'email' => 'qa-inventory-pos-admin@radium.local',
    'password' => Hash::make('password'),
    'is_active' => true,
]);
$admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

$hardware = User::factory()->create([
    'name' => 'QA Hardware',
    'email' => 'qa-inventory-pos-hardware@radium.local',
    'password' => Hash::make('password'),
    'is_active' => true,
]);
$hardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

$unassignedHardware = User::factory()->create([
    'name' => 'QA Hardware Unassigned',
    'email' => 'qa-inventory-pos-unassigned@radium.local',
    'password' => Hash::make('password'),
    'is_active' => true,
]);
$unassignedHardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

$agent = User::factory()->create([
    'name' => 'QA Agent',
    'email' => 'qa-inventory-pos-agent@radium.local',
    'password' => Hash::make('password'),
    'is_active' => true,
]);
$agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

$branchA = InventoryBranch::query()->create([
    'code' => 'QAA',
    'name' => 'QA Counter A',
    'is_active' => true,
]);
$branchB = InventoryBranch::query()->create([
    'code' => 'QAB',
    'name' => 'QA Warehouse B',
    'is_active' => true,
]);
InventoryUserBranch::query()->create([
    'user_id' => $hardware->id,
    'branch_id' => $branchA->id,
]);

InventoryProduct::query()->create([
    'sku' => 'MFS110-BROWSER',
    'name' => 'Mantra MFS110 Browser QA',
    'gst_percentage' => 18,
    'unit_price' => 2500,
    'is_serialized' => true,
    'is_active' => true,
]);

$quantity = InventoryProduct::query()->create([
    'sku' => 'OTG-BROWSER',
    'name' => 'OTG Cable Browser QA',
    'gst_percentage' => 18,
    'unit_price' => 50,
    'is_serialized' => false,
    'is_active' => true,
]);
$quantity->variants()->create([
    'sku' => 'OTG-BROWSER-1M',
    'name' => '1 metre',
    'unit_price' => 40,
    'is_active' => true,
]);

echo json_encode([
    'admin_email' => $admin->email,
    'hardware_email' => $hardware->email,
    'unassigned_email' => $unassignedHardware->email,
    'agent_email' => $agent->email,
    'branch_a' => $branchA->code,
    'branch_b' => $branchB->code,
], JSON_THROW_ON_ERROR).PHP_EOL;
