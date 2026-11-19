<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Feature\StatutoryInvoice\Support\BuyerGstB2cConversionMysqlGate;

require dirname(__DIR__, 4).'/vendor/autoload.php';
require_once __DIR__.'/BuyerGstB2cConversionMysqlGate.php';
require_once __DIR__.'/BuyerGstB2cConversionMysqlPrepareLock.php';

/**
 * @return array{host: string, port: string, database: string, username: string, password: string}
 */
function buyer_gst_b2c_conversion_mysql_credentials_from_env(): array
{
    $host = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_PORT') ?: '3306';
    $database = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_DATABASE') ?: BuyerGstB2cConversionMysqlGate::DATABASE;
    $username = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_USERNAME') ?: '';
    $password = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_PASSWORD') !== false
        ? (string) getenv('BUYER_GST_B2C_CONVERSION_MYSQL_PASSWORD')
        : '';

    if (! BuyerGstB2cConversionMysqlGate::isAllowedHost($host)) {
        fwrite(STDERR, "Refusing MySQL host [{$host}]. Loopback only.\n");
        exit(2);
    }

    if (! BuyerGstB2cConversionMysqlGate::isAllowedDatabase($database)) {
        fwrite(STDERR, "Refusing MySQL database [{$database}]. Allowed: ".BuyerGstB2cConversionMysqlGate::DATABASE."\n");
        exit(2);
    }

    if ($username === '') {
        fwrite(STDERR, "BUYER_GST_B2C_CONVERSION_MYSQL_USERNAME is required for the throwaway test database.\n");
        exit(2);
    }

    return compact('host', 'port', 'database', 'username', 'password');
}

/**
 * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
 */
function buyer_gst_b2c_conversion_mysql_boot_app(array $credentials): Application
{
    $appEnv = getenv('APP_ENV') ?: 'testing';
    if (! BuyerGstB2cConversionMysqlGate::isAllowedAppEnv($appEnv)) {
        fwrite(STDERR, "Refusing APP_ENV [{$appEnv}] for GST B2C conversion MySQL helpers.\n");
        exit(2);
    }

    BuyerGstB2cConversionMysqlGate::forceProcessEnv([
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
        'statutory_invoices.buyer_gstin_verification.enabled' => true,
        'statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => true,
    ]);

    return $app;
}
