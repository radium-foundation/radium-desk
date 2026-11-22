<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PreShipmentCancellation\Support\PreShipmentCancellationMariaDbGate;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function preship_mysql_credentials_from_env(): array
{
    $host = getenv('PRE_SHIPMENT_MARIADB_HOST') ?: '127.0.0.1';
    $port = getenv('PRE_SHIPMENT_MARIADB_PORT') ?: '33118';
    $database = getenv('PRE_SHIPMENT_MARIADB_DATABASE') ?: PreShipmentCancellationMariaDbGate::DATABASE;
    $username = getenv('PRE_SHIPMENT_MARIADB_USERNAME') ?: 'rd_preship_test';
    $password = getenv('PRE_SHIPMENT_MARIADB_PASSWORD') !== false
        ? (string) getenv('PRE_SHIPMENT_MARIADB_PASSWORD')
        : 'rd_preship_test_pw';

    if (! PreShipmentCancellationMariaDbGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing host [{$host}].\n");
        exit(2);
    }

    if (! PreShipmentCancellationMariaDbGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing database [{$database}].\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function preship_mysql_boot_app(array $credentials): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! PreShipmentCancellationMariaDbGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}].\n");
        exit(2);
    }

    PreShipmentCancellationMariaDbGate::forceProcessEnv([
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
        'PRE_SHIPMENT_CANCELLATION_ENABLED' => 'true',
    ]);

    $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    config([
        'database.default' => 'mysql',
        'pre_shipment_cancellation.enabled' => true,
        'channel_ingest.auto_issue_invoice' => false,
        'hardware_fulfilment.correlate_cashfree' => false,
    ]);

    DB::purge('mysql');
    DB::reconnect('mysql');

    return $app;
}
