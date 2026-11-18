<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Refunds\Support\RefundCompletionMysqlGate;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function refund_completion_mysql_credentials_from_env(): array
{
    $host = getenv('REFUND_COMPLETION_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('REFUND_COMPLETION_MYSQL_PORT') ?: '3306';
    $database = getenv('REFUND_COMPLETION_MYSQL_DATABASE') ?: RefundCompletionMysqlGate::DATABASE;
    $username = getenv('REFUND_COMPLETION_MYSQL_USERNAME') ?: '';
    $password = getenv('REFUND_COMPLETION_MYSQL_PASSWORD') !== false ? (string) getenv('REFUND_COMPLETION_MYSQL_PASSWORD') : '';

    if (! RefundCompletionMysqlGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing MySQL host [{$host}]. Loopback only.\n");
        exit(2);
    }

    if (! RefundCompletionMysqlGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing MySQL database [{$database}]. Allowed: ".RefundCompletionMysqlGate::DATABASE."\n");
        exit(2);
    }

    if ($username === '') {
        fwrite(STDERR, "REFUND_COMPLETION_MYSQL_USERNAME is required for the throwaway test database.\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function refund_completion_mysql_boot_app(array $credentials, ?string $deskRefundReference = null): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! RefundCompletionMysqlGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}] for refund completion MySQL helpers.\n");
        exit(2);
    }

    RefundCompletionMysqlGate::forceProcessEnv([
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
        'central_wallet.wallet_refund.desk_first_enabled' => true,
        'central_wallet.wallet_refund.desk_authoritative_enabled' => true,
        'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in,rdservice.net,radiumbox.com',
        'rdservice_in.wallet_refund_credit_enabled' => true,
        'order_lookup.spokes.rdservice_in.enabled' => true,
        'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
        'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
        'order_lookup.spokes.rdservice_in.connect_timeout_seconds' => 3,
        'order_lookup.spokes.rdservice_in.timeout_seconds' => 8,
        'cashfree.system_user_email' => 'ops@example.com',
        'interakt.templates.refund_confirmation.enabled' => false,
        'mail.enabled' => false,
        'notifications.email.enabled' => false,
        'statutory_invoices.post_finance_journals' => false,
        'statutory_invoices.worker_may_mint' => false,
        'statutory_invoices.einvoice.provider' => 'none',
    ]);

    $deskReference = $deskRefundReference ?? 'REF-CONC-DEFAULT';
    Http::fake([
        'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
            'status' => 201,
            'message' => 'Central Wallet audit recorded',
            'data' => [
                'wallet_transaction_id' => 92001,
                'wallet_reference' => 'RD92001',
                'desk_refund_reference' => $deskReference,
                'credit' => '617.00',
                'currency' => 'INR',
                'balance' => '617.00',
                'destination' => 'central_wallet',
            ],
        ], 201),
    ]);

    DB::purge('mysql');
    DB::reconnect('mysql');

    $actual = DB::selectOne('select database() as d');
    $name = is_object($actual) ? (string) $actual->d : '';
    if (! RefundCompletionMysqlGate::isAllowedDatabase($name)) {
        fwrite(STDERR, "Refusing to continue: connected database is [{$name}].\n");
        exit(2);
    }

    return $app;
}
