<?php

namespace Tests\Feature\CentralWallet;

use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\CentralWallet\Support\CentralWalletCeremonyMariaDbGate;
use Tests\TestCase;

class CentralWalletCeremonyMariaDbMigrationTest extends TestCase
{
    public const SAFE_DATABASE = CentralWalletCeremonyMariaDbGate::DATABASE;

    public function test_gate_allows_only_loopback_hosts_and_the_named_test_database(): void
    {
        $this->assertTrue(CentralWalletCeremonyMariaDbGate::isAllowedHost('127.0.0.1'));
        $this->assertTrue(CentralWalletCeremonyMariaDbGate::isAllowedHost('localhost'));
        $this->assertFalse(CentralWalletCeremonyMariaDbGate::isAllowedHost('187.127.129.16'));
        $this->assertTrue(CentralWalletCeremonyMariaDbGate::isAllowedDatabase(self::SAFE_DATABASE));
        $this->assertFalse(CentralWalletCeremonyMariaDbGate::isAllowedDatabase('radium_desk'));
        $this->assertFalse(CentralWalletCeremonyMariaDbGate::isAllowedAppEnv('production'));
    }

    public function test_mariadb_wallet_generated_column_expression_is_supported(): void
    {
        $pdo = $this->probeSafeMariaDbOrSkip();

        $pdo->exec('DROP TABLE IF EXISTS cw_mariadb_expr_probe');
        $pdo->exec(
            'CREATE TABLE cw_mariadb_expr_probe (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_code VARCHAR(64) NOT NULL,
                central_wallet_id CHAR(36) NOT NULL,
                status VARCHAR(32) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            "ALTER TABLE cw_mariadb_expr_probe
                ADD COLUMN active_site_wallet_uniq_key VARCHAR(200)
                GENERATED ALWAYS AS (
                    CASE WHEN status = 'active' THEN CONCAT(site_code, CHAR(1), RTRIM(central_wallet_id)) ELSE NULL END
                ) STORED"
        );

        $pdo->exec(
            'CREATE UNIQUE INDEX cw_expr_probe_wallet_active_uq ON cw_mariadb_expr_probe (active_site_wallet_uniq_key)'
        );

        $this->assertSame(
            '11.8.',
            substr((string) $pdo->query('select version()')->fetchColumn(), 0, 5)
        );
    }

    public function test_mariadb_fresh_migration_creates_both_active_link_indexes(): void
    {
        $pdo = $this->prepareMariaDbSchemaOrSkip();

        $this->assertIndexExists($pdo, 'cw_account_links_site_user_active_uq');
        $this->assertIndexExists($pdo, 'cw_account_links_site_wallet_active_uq');
        $this->assertGeneratedColumnExists($pdo, 'active_site_user_uniq_key');
        $this->assertGeneratedColumnExists($pdo, 'active_site_wallet_uniq_key');
    }

    public function test_mariadb_migration_completes_from_partial_production_state(): void
    {
        $pdo = $this->prepareMariaDbSchemaOrSkip();

        $pdo->exec('DROP INDEX cw_account_links_site_wallet_active_uq ON central_wallet_account_links');
        $pdo->exec('ALTER TABLE central_wallet_account_links DROP COLUMN active_site_wallet_uniq_key');
        $pdo->exec("DELETE FROM migrations WHERE migration = '2026_09_28_150000_create_central_wallet_ceremony_tables'");

        $this->assertGeneratedColumnExists($pdo, 'active_site_user_uniq_key');
        $this->assertIndexExists($pdo, 'cw_account_links_site_user_active_uq');
        $this->assertFalse($this->generatedColumnExists($pdo, 'active_site_wallet_uniq_key'));
        $this->assertFalse($this->indexExists($pdo, 'cw_account_links_site_wallet_active_uq'));

        $this->runCeremonyMigration();

        $this->assertIndexExists($pdo, 'cw_account_links_site_user_active_uq');
        $this->assertIndexExists($pdo, 'cw_account_links_site_wallet_active_uq');
        $this->assertGeneratedColumnExists($pdo, 'active_site_wallet_uniq_key');
    }

    public function test_mariadb_active_link_uniqueness_constraints(): void
    {
        $pdo = $this->prepareMariaDbSchemaOrSkip();
        $walletA = '11111111-1111-1111-1111-111111111111';
        $walletB = '22222222-2222-2222-2222-222222222222';

        $pdo->exec('DELETE FROM central_wallet_account_links');
        $pdo->exec('DELETE FROM central_wallets');
        $pdo->exec("INSERT INTO central_wallets (id, status, created_at, updated_at) VALUES ('{$walletA}', 'active', NOW(), NOW()), ('{$walletB}', 'active', NOW(), NOW())");
        $pdo->exec(
            "INSERT INTO central_wallet_account_links (central_wallet_id, site_code, local_user_id, status, created_by, created_at, updated_at)
             VALUES ('{$walletA}', 'radiumbox.com', '42', 'active', 'test', NOW(), NOW())"
        );

        try {
            $pdo->exec(
                "INSERT INTO central_wallet_account_links (central_wallet_id, site_code, local_user_id, status, created_by, created_at, updated_at)
                 VALUES ('{$walletB}', 'radiumbox.com', '42', 'active', 'test', NOW(), NOW())"
            );
            $this->fail('Expected duplicate active site/user link to be rejected.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('1062', $exception->getMessage());
        }

        try {
            $pdo->exec(
                "INSERT INTO central_wallet_account_links (central_wallet_id, site_code, local_user_id, status, created_by, created_at, updated_at)
                 VALUES ('{$walletA}', 'radiumbox.com', '43', 'active', 'test', NOW(), NOW())"
            );
            $this->fail('Expected duplicate active site/wallet link to be rejected.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('1062', $exception->getMessage());
        }

        $pdo->exec(
            "INSERT INTO central_wallet_account_links (central_wallet_id, site_code, local_user_id, status, created_by, created_at, updated_at) VALUES
                ('{$walletA}', 'radiumbox.com', '42', 'revoked', 'test', NOW(), NOW()),
                ('{$walletA}', 'radiumbox.com', '42', 'pending_verification', 'test', NOW(), NOW()),
                ('{$walletA}', 'other.site', '42', 'active', 'test', NOW(), NOW())"
        );

        $this->assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM central_wallet_account_links')->fetchColumn());
    }

    private function prepareMariaDbSchemaOrSkip(): PDO
    {
        $pdo = $this->probeSafeMariaDbOrSkip();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.self::SAFE_DATABASE.'`');
        $pdo->exec('USE `'.self::SAFE_DATABASE.'`');
        $this->resetCentralWalletTables($pdo);
        $this->runFoundationMigration();
        $this->runCeremonyMigration();

        return $pdo;
    }

    private function resetCentralWalletTables(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DROP TABLE IF EXISTS central_wallet_ceremony_proof_consumptions');
        $pdo->exec('DROP TABLE IF EXISTS central_wallet_ceremony_identities');
        $pdo->exec('DROP TABLE IF EXISTS central_wallet_reconciliation_items');
        $pdo->exec('DROP TABLE IF EXISTS central_wallet_reconciliation_runs');
        $pdo->exec('DROP TABLE IF EXISTS central_wallet_audit_events');
        $pdo->exec('DROP TABLE IF EXISTS central_wallet_idempotency_records');
        $pdo->exec('DROP TABLE IF EXISTS central_wallet_ledger_entries');
        $pdo->exec('DROP TABLE IF EXISTS central_wallet_account_links');
        $pdo->exec('DROP TABLE IF EXISTS central_wallets');
        $pdo->exec('DROP TABLE IF EXISTS migrations');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function runFoundationMigration(): void
    {
        $this->runArtisanMigrate('database/migrations/2026_09_28_100000_create_central_wallet_foundation_tables.php');
    }

    private function runCeremonyMigration(): void
    {
        $this->runArtisanMigrate('database/migrations/2026_09_28_150000_create_central_wallet_ceremony_tables.php');
    }

    private function runArtisanMigrate(string $path): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'migrate', '--path='.$path, '--force'],
            base_path(),
            $this->migrationEnv(),
            null,
            120
        );
        $process->mustRun();
    }

    /**
     * @return array<string, string>
     */
    private function migrationEnv(): array
    {
        $host = getenv('CENTRAL_WALLET_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('CENTRAL_WALLET_MYSQL_PORT') ?: '';
        $database = getenv('CENTRAL_WALLET_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('CENTRAL_WALLET_MYSQL_USERNAME') ?: 'root';
        $password = getenv('CENTRAL_WALLET_MYSQL_PASSWORD') !== false ? (string) getenv('CENTRAL_WALLET_MYSQL_PASSWORD') : '';
        $socket = getenv('CENTRAL_WALLET_MYSQL_SOCKET') ?: '';

        $env = [
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

        if ($socket !== '') {
            $env['DB_HOST'] = 'localhost';
            $env['DB_SOCKET'] = $socket;
        }

        return $env;
    }

    private function probeSafeMariaDbOrSkip(): PDO
    {
        $host = getenv('CENTRAL_WALLET_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('CENTRAL_WALLET_MYSQL_PORT') ?: '';
        $database = getenv('CENTRAL_WALLET_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('CENTRAL_WALLET_MYSQL_USERNAME') ?: 'root';
        $password = getenv('CENTRAL_WALLET_MYSQL_PASSWORD') !== false ? (string) getenv('CENTRAL_WALLET_MYSQL_PASSWORD') : '';
        $socket = getenv('CENTRAL_WALLET_MYSQL_SOCKET') ?: '';

        $this->assertTrue(
            CentralWalletCeremonyMariaDbGate::isAllowedHost($host),
            'Refusing MariaDB host '.$host.'. Loopback only.'
        );
        $this->assertTrue(
            CentralWalletCeremonyMariaDbGate::isAllowedDatabase($database),
            'Refusing MariaDB database '.$database.'. Allowed: '.self::SAFE_DATABASE
        );
        $this->assertTrue(
            CentralWalletCeremonyMariaDbGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')),
            'Refusing to run MariaDB ceremony helpers outside local/testing.'
        );

        if ($socket === '' && ($port === '' || $port === '3306')) {
            $this->markTestSkipped(
                'MariaDB test environment unavailable. Set CENTRAL_WALLET_MYSQL_PORT to a disposable loopback '.
                'MariaDB 11.8 listener (for example 3307) or CENTRAL_WALLET_MYSQL_SOCKET before running this suite.'
            );
        }

        try {
            if ($socket !== '') {
                $pdo = new PDO(
                    sprintf('mysql:unix_socket=%s;charset=utf8mb4', $socket),
                    $username,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
                );
            } else {
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
                    $username,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
                );
            }

            $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'`');
            $pdo->exec('USE `'.$database.'`');
        } catch (PDOException $exception) {
            $this->markTestSkipped(
                'MariaDB test environment unavailable on '.
                ($socket !== '' ? 'socket '.$socket : $host.':'.$port).
                ' ('.$exception->getMessage().').'
            );
        }

        $version = (string) $pdo->query('select version()')->fetchColumn();
        if (! str_contains(strtolower($version), 'mariadb')) {
            $this->markTestSkipped('Expected MariaDB server, found: '.$version);
        }

        return $pdo;
    }

    private function indexExists(PDO $pdo, string $indexName): bool
    {
        $statement = $pdo->prepare('SHOW INDEX FROM central_wallet_account_links WHERE Key_name = ?');
        $statement->execute([$indexName]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function generatedColumnExists(PDO $pdo, string $columnName): bool
    {
        $statement = $pdo->prepare('SHOW COLUMNS FROM central_wallet_account_links LIKE ?');
        $statement->execute([$columnName]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function assertIndexExists(PDO $pdo, string $indexName): void
    {
        $this->assertTrue($this->indexExists($pdo, $indexName), 'Expected index '.$indexName.' to exist.');
    }

    private function assertGeneratedColumnExists(PDO $pdo, string $columnName): void
    {
        $this->assertTrue(
            $this->generatedColumnExists($pdo, $columnName),
            'Expected generated column '.$columnName.' to exist.'
        );
    }
}
