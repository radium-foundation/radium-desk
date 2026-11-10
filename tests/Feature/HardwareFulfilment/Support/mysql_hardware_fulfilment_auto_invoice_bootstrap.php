<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\HardwareFulfilment\Support\HardwareFulfilmentMysqlGate;

require __DIR__.'/mysql_hardware_fulfilment_bootstrap.php';

/**
 * Production-equivalent post-allocation statutory invoice path enabled for
 * isolated MariaDB concurrency workers (location seller + allocate → issue).
 *
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function hardware_fulfilment_mysql_boot_app_with_auto_invoice(array $credentials): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! HardwareFulfilmentMysqlGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}] for hardware fulfilment MySQL helpers.\n");
        exit(2);
    }

    HardwareFulfilmentMysqlGate::forceProcessEnv([
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $credentials['host'],
        'DB_PORT' => $credentials['port'],
        'DB_DATABASE' => $credentials['database'],
        'DB_USERNAME' => $credentials['username'],
        'DB_PASSWORD' => $credentials['password'],
        'DB_URL' => '',
        'DB_SOCKET' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'HARDWARE_FULFILMENT_MYSQL_AUTO_INVOICE' => '1',
    ]);

    $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    Storage::fake('local');

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => $credentials['host'],
        'database.connections.mysql.port' => $credentials['port'],
        'database.connections.mysql.database' => $credentials['database'],
        'database.connections.mysql.username' => $credentials['username'],
        'database.connections.mysql.password' => $credentials['password'],
        'database.connections.mysql.unix_socket' => '',
        'database.connections.mysql.url' => null,
        'channel_ingest.secrets.radiumbox_com' => 'test-radiumbox-secret',
        // Ingest must stay off; CA cutover guard rejects ingest-time auto issue in tests.
        'channel_ingest.auto_issue_invoice' => false,
        'hardware_fulfilment.post_allocate_auto_statutory_invoice' => true,
        'hardware_fulfilment.correlate_cashfree' => false,
        'statutory_invoices.series_code' => '',
        'statutory_invoices.number_format' => '',
        'statutory_invoices.post_finance_journals' => false,
        'statutory_invoices.auto_issue_on_pos_complete' => false,
        'statutory_invoices.worker_may_mint' => false,
        'statutory_invoices.einvoice.provider' => 'none',
        'location_seller_identity.auto_enable' => false,
        'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
        'statutory_invoices.gstin_scope' => '',
        'statutory_invoices.seller_address' => '',
        'statutory_invoices.seller_state' => '',
        'statutory_invoices.location_series.enabled' => true,
        'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
        'statutory_invoices.location_series.locations.delhi.address' => 'Test-only Delhi registered address',
        'statutory_invoices.location_series.locations.delhi.state' => 'Delhi',
        'statutory_invoices.location_series.locations.mumbai.gstin' => '27AAICP1128M1Z7',
        'statutory_invoices.location_series.locations.mumbai.address' => 'Test-only Mumbai registered address',
        'statutory_invoices.location_series.locations.mumbai.state' => 'Maharashtra',
    ]);

    if (! (bool) config('hardware_fulfilment.post_allocate_auto_statutory_invoice')
        || ! (bool) config('statutory_invoices.location_series.enabled')) {
        fwrite(STDERR, "Auto-invoice MariaDB harness refused to start: post-allocate issuance config not enabled.\n");
        exit(2);
    }

    DB::purge('mysql');
    DB::reconnect('mysql');

    $actual = DB::selectOne('select database() as d');
    $name = is_object($actual) ? (string) $actual->d : '';
    if (! HardwareFulfilmentMysqlGate::isAllowedDatabase($name)) {
        fwrite(STDERR, "Refusing to continue: connected database is [{$name}].\n");
        exit(2);
    }

    return $app;
}
