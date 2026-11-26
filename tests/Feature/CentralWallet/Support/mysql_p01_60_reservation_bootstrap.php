<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\CentralWallet\Support\P01_60ReservationMysqlGate;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function p01_60_reservation_mysql_credentials_from_env(): array
{
    $host = getenv('P01_60_RESERVATION_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('P01_60_RESERVATION_MYSQL_PORT') ?: '33118';
    $database = getenv('P01_60_RESERVATION_MYSQL_DATABASE') ?: P01_60ReservationMysqlGate::DATABASE;
    $username = getenv('P01_60_RESERVATION_MYSQL_USERNAME') ?: 'rd_p01_60_reservation_test';
    $password = getenv('P01_60_RESERVATION_MYSQL_PASSWORD') !== false
        ? (string) getenv('P01_60_RESERVATION_MYSQL_PASSWORD')
        : 'rd_p01_60_reservation_test_pw';

    if (! P01_60ReservationMysqlGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing MySQL host [{$host}]. Loopback only.\n");
        exit(2);
    }

    if (! P01_60ReservationMysqlGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing MySQL database [{$database}].\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function p01_60_reservation_mysql_boot_app(array $credentials, string $integrationToken): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! P01_60ReservationMysqlGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}].\n");
        exit(2);
    }

    P01_60ReservationMysqlGate::forceProcessEnv([
        'APP_ENV' => $appEnv,
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
        'central_wallet.enabled' => true,
        'central_wallet.api_enabled' => true,
        'central_wallet.integration_token' => $integrationToken,
        'central_wallet.reservations.enabled' => true,
        'central_wallet.historical_wallet_visibility.enabled' => true,
        'central_wallet.spend_authorization.p01_60_verified_email_spend_enabled' => true,
        'central_wallet.reservation_ttl_seconds' => 900,
    ]);

    return $app;
}

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $body
 * @return array{http_status: int, body: mixed}
 */
function p01_60_reservation_http_post(Application $app, array $context, string $uri, array $body): array
{
    $kernel = $app->make(HttpKernel::class);
    $request = Request::create($uri, 'POST', $body);
    $request->headers->set('Authorization', 'Bearer '.(string) $context['integration_token']);
    $request->headers->set('X-Site-Code', (string) $context['site_code']);
    $request->headers->set('Accept', 'application/json');
    $response = $kernel->handle($request);
    $decoded = json_decode((string) $response->getContent(), true);

    return [
        'http_status' => $response->getStatusCode(),
        'body' => $decoded,
    ];
}

function p01_60_reservation_credit_balance(string $centralWalletId, string $amount, string $siteCode): void
{
    DB::table('central_wallet_ledger_entries')->insert([
        'id' => (string) Str::uuid(),
        'central_wallet_id' => $centralWalletId,
        'entry_type' => 'credit',
        'amount' => $amount,
        'currency' => 'INR',
        'status' => 'posted',
        'source_system' => $siteCode,
        'correlation_id' => (string) Str::uuid(),
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
