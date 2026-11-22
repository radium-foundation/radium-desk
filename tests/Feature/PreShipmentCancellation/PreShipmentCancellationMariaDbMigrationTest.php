<?php

namespace Tests\Feature\PreShipmentCancellation;

use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\PreShipmentCancellation\Support\PreShipmentCancellationMariaDbGate;
use Tests\TestCase;

class PreShipmentCancellationMariaDbMigrationTest extends TestCase
{
    private const MIGRATION_CREATE = 'database/migrations/2026_11_22_230000_create_pre_shipment_cancellation_requests_table.php';

    private const MIGRATION_BLOCK = 'database/migrations/2026_11_22_230001_add_preship_cancellation_block_to_commerce_orders.php';

    public function test_gate_rejects_production_targets(): void
    {
        $this->assertFalse(PreShipmentCancellationMariaDbGate::isAllowedDatabase('radium_desk'));
        $this->assertFalse(PreShipmentCancellationMariaDbGate::isAllowedHost('187.127.129.16'));
    }

    public function test_mariadb_full_migrate_preship_up_rollback_up(): void
    {
        $pdo = $this->probeSafeMariaDbOrSkip();

        $version = (string) $pdo->query('select version()')->fetchColumn();
        $this->assertStringContainsStringIgnoringCase('mariadb', $version);

        $this->wipeSchema();
        $this->runArtisanMigrate();

        $this->assertTableExists($pdo, 'pre_shipment_cancellation_requests');
        $this->assertColumnExists($pdo, 'commerce_orders', 'hardware_fulfilment_blocked_at');
        $this->assertColumnExists($pdo, 'commerce_orders', 'pre_shipment_cancellation_request_id');
        $this->assertIndexExists($pdo, 'pre_shipment_cancellation_requests', 'psc_req_reference_no_uq');
        $this->assertForeignKeyOnColumnExists($pdo, 'pre_shipment_cancellation_requests', 'order_id');

        $this->runArtisanRollback(self::MIGRATION_BLOCK);
        $this->assertColumnMissing($pdo, 'commerce_orders', 'hardware_fulfilment_blocked_at');
        $this->assertColumnMissing($pdo, 'commerce_orders', 'pre_shipment_cancellation_request_id');

        $this->runArtisanRollback(self::MIGRATION_CREATE);
        $this->assertTableMissing($pdo, 'pre_shipment_cancellation_requests');

        $this->runArtisanMigratePath(self::MIGRATION_CREATE);
        $this->runArtisanMigratePath(self::MIGRATION_BLOCK);

        $this->assertTableExists($pdo, 'pre_shipment_cancellation_requests');
        $this->assertColumnExists($pdo, 'commerce_orders', 'hardware_fulfilment_blocked_at');
    }

    private function probeSafeMariaDbOrSkip(): PDO
    {
        $host = getenv('PRE_SHIPMENT_MARIADB_HOST') ?: '127.0.0.1';
        $port = getenv('PRE_SHIPMENT_MARIADB_PORT') ?: '';
        $database = getenv('PRE_SHIPMENT_MARIADB_DATABASE') ?: PreShipmentCancellationMariaDbGate::DATABASE;
        $username = getenv('PRE_SHIPMENT_MARIADB_USERNAME') ?: 'root';
        $password = getenv('PRE_SHIPMENT_MARIADB_PASSWORD') !== false
            ? (string) getenv('PRE_SHIPMENT_MARIADB_PASSWORD')
            : '';

        $this->assertTrue(PreShipmentCancellationMariaDbGate::isAllowedHost($host));
        $this->assertTrue(PreShipmentCancellationMariaDbGate::isAllowedDatabase($database));
        $this->assertTrue(
            PreShipmentCancellationMariaDbGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')),
        );

        if ($port === '' || $port === '3306') {
            $this->markTestSkipped(
                'Disposable MariaDB unavailable: set PRE_SHIPMENT_MARIADB_PORT to a non-3306 loopback listener '.
                '(for example 33118) or use the GitHub Actions service job.'
            );
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'`');
            $pdo->exec('USE `'.$database.'`');
        } catch (PDOException $exception) {
            $this->markTestSkipped('MariaDB probe failed: '.$exception->getMessage());
        }

        $engine = strtolower((string) $pdo->query('select version()')->fetchColumn());
        if (! str_contains($engine, 'mariadb')) {
            $this->markTestSkipped('Expected MariaDB, found: '.$engine);
        }

        return $pdo;
    }

    private function wipeSchema(): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'db:wipe', '--force', '--drop-views'],
            base_path(),
            $this->migrationEnv(),
            null,
            600,
        );
        $process->mustRun();
    }

    private function runArtisanMigrate(): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'migrate', '--force'],
            base_path(),
            $this->migrationEnv(),
            null,
            600,
        );
        $process->mustRun();
    }

    private function runArtisanMigratePath(string $path): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'migrate', '--path='.$path, '--force'],
            base_path(),
            $this->migrationEnv(),
            null,
            300,
        );
        $process->mustRun();
    }

    private function runArtisanRollback(string $path): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'migrate:rollback', '--path='.$path, '--force'],
            base_path(),
            $this->migrationEnv(),
            null,
            300,
        );
        $process->mustRun();
    }

    /**
     * @return array<string, string>
     */
    private function migrationEnv(): array
    {
        $host = getenv('PRE_SHIPMENT_MARIADB_HOST') ?: '127.0.0.1';
        $port = getenv('PRE_SHIPMENT_MARIADB_PORT') ?: '33118';
        $database = getenv('PRE_SHIPMENT_MARIADB_DATABASE') ?: PreShipmentCancellationMariaDbGate::DATABASE;
        $username = getenv('PRE_SHIPMENT_MARIADB_USERNAME') ?: 'root';
        $password = getenv('PRE_SHIPMENT_MARIADB_PASSWORD') !== false
            ? (string) getenv('PRE_SHIPMENT_MARIADB_PASSWORD')
            : '';

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $password,
            'DB_URL' => '',
        ];
    }

    private function assertTableExists(PDO $pdo, string $table): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);
        $this->assertSame(1, (int) $statement->fetchColumn(), 'Expected table '.$table);
    }

    private function assertTableMissing(PDO $pdo, string $table): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);
        $this->assertSame(0, (int) $statement->fetchColumn(), 'Table '.$table.' should be absent after rollback.');
    }

    private function assertColumnExists(PDO $pdo, string $table, string $column): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $statement->execute([$table, $column]);
        $this->assertSame(1, (int) $statement->fetchColumn(), 'Expected column '.$table.'.'.$column);
    }

    private function assertColumnMissing(PDO $pdo, string $table, string $column): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $statement->execute([$table, $column]);
        $this->assertSame(0, (int) $statement->fetchColumn(), 'Column '.$table.'.'.$column.' should be absent.');
    }

    private function assertIndexExists(PDO $pdo, string $table, string $index): void
    {
        $statement = $pdo->prepare('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?');
        $statement->execute([$index]);
        $this->assertNotFalse($statement->fetch(PDO::FETCH_ASSOC), 'Expected index '.$index.' on '.$table);
    }

    private function assertForeignKeyOnColumnExists(PDO $pdo, string $table, string $column): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND referenced_table_name IS NOT NULL'
        );
        $statement->execute([$table, $column]);
        $this->assertGreaterThanOrEqual(1, (int) $statement->fetchColumn(), 'Expected FK on '.$table.'.'.$column);
    }
}
