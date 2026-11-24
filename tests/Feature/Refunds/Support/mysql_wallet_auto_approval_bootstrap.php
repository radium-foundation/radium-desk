<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Refunds\Support\WalletAutoApprovalMysqlGate;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function wallet_auto_approval_mysql_credentials_from_env(): array
{
    $host = getenv('WALLET_AUTO_APPROVAL_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('WALLET_AUTO_APPROVAL_MYSQL_PORT') ?: '33118';
    $database = getenv('WALLET_AUTO_APPROVAL_MYSQL_DATABASE') ?: WalletAutoApprovalMysqlGate::DATABASE;
    $username = getenv('WALLET_AUTO_APPROVAL_MYSQL_USERNAME') ?: '';
    $password = getenv('WALLET_AUTO_APPROVAL_MYSQL_PASSWORD') !== false
        ? (string) getenv('WALLET_AUTO_APPROVAL_MYSQL_PASSWORD')
        : '';

    if (! WalletAutoApprovalMysqlGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing MySQL host [{$host}]. Loopback only.\n");
        exit(2);
    }

    if (! WalletAutoApprovalMysqlGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing MySQL database [{$database}].\n");
        exit(2);
    }

    if ($username === '') {
        fwrite(STDERR, "WALLET_AUTO_APPROVAL_MYSQL_USERNAME is required.\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function wallet_auto_approval_mysql_boot_app(array $credentials): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! WalletAutoApprovalMysqlGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}].\n");
        exit(2);
    }

    WalletAutoApprovalMysqlGate::forceProcessEnv([
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
        'APP_ENV' => 'testing',
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
        'refunds.wallet_auto_approval.enabled' => true,
        'refunds.wallet_auto_approval.spokes' => 'rdservice.in,radiumbox.com',
        'refunds.wallet_auto_approval.max_amount' => '2000',
        'central_wallet.wallet_refund.desk_first_enabled' => true,
        'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in,radiumbox.com',
        'mail.enabled' => false,
        'notifications.email.enabled' => false,
    ]);

    DB::purge('mysql');
    DB::reconnect('mysql');

    $actual = DB::selectOne('select database() as d');
    $name = is_object($actual) ? (string) $actual->d : '';
    if (! WalletAutoApprovalMysqlGate::isAllowedDatabase($name)) {
        fwrite(STDERR, "Connected database [{$name}] is not allowed.\n");
        exit(2);
    }

    return $app;
}
