<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ServiceCaseAssignment\Support\ServiceCaseAssignmentMysqlGate;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function service_case_assignment_mysql_credentials_from_env(): array
{
    $host = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PORT') ?: '3306';
    $database = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_DATABASE') ?: ServiceCaseAssignmentMysqlGate::DATABASE;
    $username = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_USERNAME') ?: '';
    $password = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PASSWORD') !== false ? (string) getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PASSWORD') : '';

    if (! ServiceCaseAssignmentMysqlGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing MySQL host [{$host}]. Loopback only.\n");
        exit(2);
    }

    if (! ServiceCaseAssignmentMysqlGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing MySQL database [{$database}]. Allowed: ".ServiceCaseAssignmentMysqlGate::DATABASE."\n");
        exit(2);
    }

    if ($username === '') {
        fwrite(STDERR, "SERVICE_CASE_ASSIGNMENT_MYSQL_USERNAME is required for the throwaway test database.\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function service_case_assignment_mysql_boot_app(array $credentials): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! ServiceCaseAssignmentMysqlGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}] for service case assignment MySQL helpers.\n");
        exit(2);
    }

    ServiceCaseAssignmentMysqlGate::forceProcessEnv([
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
        'service_case_assignment.automation_grace_period_enabled' => true,
        'service_case_assignment.round_robin_enabled' => true,
    ]);

    $connected = DB::selectOne('select database() as d');
    if (! is_object($connected) || ! ServiceCaseAssignmentMysqlGate::isAllowedDatabase((string) $connected->d)) {
        fwrite(STDERR, "Bootstrap refused: unexpected database.\n");
        exit(2);
    }

    return $app;
}
