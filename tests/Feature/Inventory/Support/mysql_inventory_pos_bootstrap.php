<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inventory\Support\InventoryPosMysqlGate;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function inventory_pos_mysql_credentials_from_env(): array
{
    $host = getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('INVENTORY_POS_MYSQL_PORT') ?: '3306';
    $database = getenv('INVENTORY_POS_MYSQL_DATABASE') ?: InventoryPosMysqlGate::DATABASE;
    $username = getenv('INVENTORY_POS_MYSQL_USERNAME') ?: '';
    $password = getenv('INVENTORY_POS_MYSQL_PASSWORD') !== false ? (string) getenv('INVENTORY_POS_MYSQL_PASSWORD') : '';

    if (! InventoryPosMysqlGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing MySQL host [{$host}]. Loopback only.\n");
        exit(2);
    }

    if (! InventoryPosMysqlGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing MySQL database [{$database}]. Allowed: ".InventoryPosMysqlGate::DATABASE."\n");
        exit(2);
    }

    if ($username === '') {
        fwrite(STDERR, "INVENTORY_POS_MYSQL_USERNAME is required for the throwaway test database.\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function inventory_pos_mysql_boot_app(array $credentials): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! InventoryPosMysqlGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}] for inventory POS MySQL helpers.\n");
        exit(2);
    }

    InventoryPosMysqlGate::forceProcessEnv([
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
    ]);

    DB::purge('mysql');
    DB::reconnect('mysql');

    $actual = DB::selectOne('select database() as d');
    $name = is_object($actual) ? (string) $actual->d : '';
    if (! InventoryPosMysqlGate::isAllowedDatabase($name)) {
        fwrite(STDERR, "Refusing to continue: connected database is [{$name}].\n");
        exit(2);
    }

    return $app;
}
