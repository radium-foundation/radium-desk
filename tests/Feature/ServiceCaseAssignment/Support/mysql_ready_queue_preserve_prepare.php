<?php

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ServiceCaseAssignment\Support\ServiceCaseAssignmentMysqlGate;
use Tests\Feature\ServiceCaseAssignment\Support\ServiceCaseAssignmentMysqlPrepareLock;

require __DIR__.'/mysql_service_case_assignment_bootstrap.php';

$lockHandle = ServiceCaseAssignmentMysqlPrepareLock::acquire();
register_shutdown_function(
    static function () use ($lockHandle): void {
        ServiceCaseAssignmentMysqlPrepareLock::release($lockHandle);
    }
);

$credentials = service_case_assignment_mysql_credentials_from_env();
$app = service_case_assignment_mysql_boot_app($credentials);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! ServiceCaseAssignmentMysqlGate::isAllowedDatabase((string) $connected->d)) {
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
app()->make(SettingsSeeder::class)->run();

Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

$admin = User::factory()->create([
    'name' => 'Ready Admin',
    'email' => 'ready-admin-conc@example.com',
    'is_active' => true,
]);
$admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

$agent = User::factory()->create([
    'name' => 'Support Owner',
    'email' => 'support-owner-conc@example.com',
    'is_active' => true,
]);
$agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

$actor = User::factory()->create([
    'name' => 'Validation Actor',
    'email' => 'validation-actor-conc@example.com',
    'is_active' => true,
]);

app(SettingService::class)->setMany([
    'assignment.timezone' => 'Asia/Kolkata',
    'assignment.day_shift_start' => '09:00',
    'assignment.day_shift_end' => '18:30',
    'assignment.day_shift_admin_user_id' => (string) $admin->id,
    'assignment.night_shift_admin_user_id' => (string) $admin->id,
    'assignment.fallback_admin_1_user_id' => '',
    'assignment.fallback_admin_2_user_id' => '',
    'assignment.automation_grace_period_seconds' => '60',
]);

$order = Order::query()->create([
    'order_id' => 'RD-INNODB-PRESERVE-CONC',
    'serial_number' => '7881953',
    'product_name' => 'MFS 110',
    'device_model' => 'MFS 110',
    'status' => 'active',
    'created_by' => $actor->id,
    'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
]);
app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

$incident = Incident::query()->create([
    'order_id' => $order->id,
    'reference_no' => app(IncidentReferenceService::class)->generate(),
    'category' => 'General',
    'source' => IncidentSource::Cashfree,
    'title' => 'InnoDB ready ownership preserve',
    'description' => 'InnoDB ready ownership preserve.',
    'status' => IncidentStatus::Open,
    'assigned_to_user_id' => $agent->id,
    'assignment_origin' => AssignmentOrigin::Manual,
    'created_by' => $actor->id,
]);

echo json_encode([
    'order_id' => $order->id,
    'incident_id' => $incident->id,
    'agent_user_id' => $agent->id,
    'admin_user_id' => $admin->id,
    'actor_user_id' => $actor->id,
    'preserved_event' => ServiceCaseAssignmentService::READY_QUEUE_OWNER_PRESERVED_EVENT,
], JSON_THROW_ON_ERROR);
