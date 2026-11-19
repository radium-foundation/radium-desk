<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

// After creating or switching worktrees, run `composer dump-autoload` so App\ maps to this tree (not a stale worktree path).
require dirname(__DIR__, 4).'/vendor/autoload.php';

require_once __DIR__.'/LeavePolicyMysqlGate.php';

use Tests\Feature\Workforce\Support\LeavePolicyMysqlGate;

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function leave_policy_mysql_credentials_from_env(): array
{
    $host = getenv('LEAVE_POLICY_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('LEAVE_POLICY_MYSQL_PORT') ?: '33118';
    $database = getenv('LEAVE_POLICY_MYSQL_DATABASE') ?: LeavePolicyMysqlGate::DATABASE;
    $username = getenv('LEAVE_POLICY_MYSQL_USERNAME') ?: '';
    $password = getenv('LEAVE_POLICY_MYSQL_PASSWORD') !== false ? (string) getenv('LEAVE_POLICY_MYSQL_PASSWORD') : '';

    if (! LeavePolicyMysqlGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing MySQL host [{$host}]. Loopback only.\n");
        exit(2);
    }

    if (! LeavePolicyMysqlGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing MySQL database [{$database}].\n");
        exit(2);
    }

    if ($username === '') {
        fwrite(STDERR, "LEAVE_POLICY_MYSQL_USERNAME is required.\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function leave_policy_mysql_boot_app(array $credentials): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! LeavePolicyMysqlGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}].\n");
        exit(2);
    }

    LeavePolicyMysqlGate::forceProcessEnv([
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
        'mail.enabled' => false,
        'notifications.email.enabled' => false,
        'leave_policy.auto_approval.enabled' => false,
        'leave_policy.auto_approval.leave_balance_ledger_available' => false,
    ]);

    DB::purge('mysql');
    DB::reconnect('mysql');

    $actual = DB::selectOne('select database() as d');
    $name = is_object($actual) ? (string) $actual->d : '';
    if (! LeavePolicyMysqlGate::isAllowedDatabase($name)) {
        fwrite(STDERR, "Connected database [{$name}] is not allowed.\n");
        exit(2);
    }

    return $app;
}
