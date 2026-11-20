<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Tests\Feature\StatutoryInvoice\Support\StatutoryCommerceMintMysqlGate;

require dirname(__DIR__, 4).'/vendor/autoload.php';
require_once __DIR__.'/StatutoryCommerceMintMysqlGate.php';

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function statutory_commerce_mint_mysql_credentials_from_env(): array
{
    $host = getenv('STATUTORY_COMMERCE_MINT_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('STATUTORY_COMMERCE_MINT_MYSQL_PORT') ?: '3306';
    $database = getenv('STATUTORY_COMMERCE_MINT_MYSQL_DATABASE') ?: StatutoryCommerceMintMysqlGate::DATABASE;
    $username = getenv('STATUTORY_COMMERCE_MINT_MYSQL_USERNAME') ?: '';
    $password = getenv('STATUTORY_COMMERCE_MINT_MYSQL_PASSWORD') !== false
        ? (string) getenv('STATUTORY_COMMERCE_MINT_MYSQL_PASSWORD')
        : '';

    if (! StatutoryCommerceMintMysqlGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing MySQL host [{$host}]. Loopback only.\n");
        exit(2);
    }

    if (! StatutoryCommerceMintMysqlGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing MySQL database [{$database}]. Allowed: ".StatutoryCommerceMintMysqlGate::DATABASE."\n");
        exit(2);
    }

    if ($username === '') {
        fwrite(STDERR, "STATUTORY_COMMERCE_MINT_MYSQL_USERNAME is required for the throwaway test database.\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function statutory_commerce_mint_mysql_boot_app(array $credentials): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! StatutoryCommerceMintMysqlGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}] for statutory commerce mint MySQL helpers.\n");
        exit(2);
    }

    StatutoryCommerceMintMysqlGate::forceProcessEnv([
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

    Carbon::setTestNow('2026-09-18 12:00:00');

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => $credentials['host'],
        'database.connections.mysql.port' => $credentials['port'],
        'database.connections.mysql.database' => $credentials['database'],
        'database.connections.mysql.username' => $credentials['username'],
        'database.connections.mysql.password' => $credentials['password'],
        'database.connections.mysql.unix_socket' => '',
        'database.connections.mysql.url' => null,
        'statutory_invoices.series_code' => '',
        'statutory_invoices.number_format' => '',
        'statutory_invoices.post_finance_journals' => false,
        'statutory_invoices.auto_issue_on_pos_complete' => false,
        'statutory_invoices.worker_may_mint' => false,
        'statutory_invoices.einvoice.provider' => 'none',
        'statutory_invoices.invoice_scope_starts_at' => '2026-09-01 00:00:00',
        'statutory_invoices.buyer_gstin_verification.enabled' => true,
        'statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => true,
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
        'channel_ingest.auto_issue_invoice' => false,
        'radiumbox.statutory_receipt_publish.enabled' => false,
        'radiumbox.statutory_receipt_publish.schedule_on_issue' => true,
    ]);

    return $app;
}
