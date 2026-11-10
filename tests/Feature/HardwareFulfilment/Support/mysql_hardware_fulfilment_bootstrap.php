<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Feature\HardwareFulfilment\Support\HardwareFulfilmentMysqlGate;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function hardware_fulfilment_mysql_credentials_from_env(): array
{
    $host = getenv('HARDWARE_FULFILMENT_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('HARDWARE_FULFILMENT_MYSQL_PORT') ?: '3306';
    $database = getenv('HARDWARE_FULFILMENT_MYSQL_DATABASE') ?: HardwareFulfilmentMysqlGate::DATABASE;
    $username = getenv('HARDWARE_FULFILMENT_MYSQL_USERNAME') ?: '';
    $password = getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD') !== false ? (string) getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD') : '';

    if (! HardwareFulfilmentMysqlGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing MySQL host [{$host}]. Loopback only.\n");
        exit(2);
    }

    if (! HardwareFulfilmentMysqlGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing MySQL database [{$database}]. Allowed: ".HardwareFulfilmentMysqlGate::DATABASE."\n");
        exit(2);
    }

    if ($username === '') {
        fwrite(STDERR, "HARDWARE_FULFILMENT_MYSQL_USERNAME is required for the throwaway test database.\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function hardware_fulfilment_mysql_boot_app(array $credentials): Application
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
    ]);

    $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

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
        'channel_ingest.auto_issue_invoice' => false,
        'hardware_fulfilment.correlate_cashfree' => false,
        'statutory_invoices.post_finance_journals' => false,
        'statutory_invoices.auto_issue_on_pos_complete' => false,
        'statutory_invoices.worker_may_mint' => false,
        'statutory_invoices.einvoice.provider' => 'none',
        'location_seller_identity.auto_enable' => false,
    ]);

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
