<?php

namespace Tests\Feature\CentralWallet;

use PDO;
use PDOException;
use Tests\TestCase;

class CentralWalletLedgerBusinessReferenceUniqueIndexMariaDbTest extends TestCase
{
    private const DATABASE = 'radium_desk_cw_ledger_index_migration_test';

    public function test_mariadb_allows_duplicate_equivalent_unique_indexes_and_migration_must_skip_second(): void
    {
        $pdo = $this->pdoOrSkip();

        $pdo->exec('DROP DATABASE IF EXISTS `'.self::DATABASE.'`');
        $pdo->exec('CREATE DATABASE `'.self::DATABASE.'`');
        $pdo->exec('USE `'.self::DATABASE.'`');

        $pdo->exec(
            'CREATE TABLE central_wallet_ledger_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                business_reference VARCHAR(191) NULL,
                entry_type VARCHAR(32) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE UNIQUE INDEX cw_ledger_business_ref_type_uq
                ON central_wallet_ledger_entries (business_reference, entry_type)'
        );

        $secondCreated = true;
        try {
            $pdo->exec(
                'CREATE UNIQUE INDEX cw_ledger_business_ref_entry_type_uq
                    ON central_wallet_ledger_entries (business_reference, entry_type)'
            );
        } catch (PDOException) {
            $secondCreated = false;
        }

        $this->assertTrue(
            $secondCreated,
            'MariaDB must allow a second unique index on the same columns (verified deploy risk).',
        );

        $indexes = $pdo->query('SHOW INDEX FROM central_wallet_ledger_entries WHERE Non_unique = 0')
            ->fetchAll(PDO::FETCH_ASSOC);

        $businessRefIndexes = array_values(array_filter(
            $indexes,
            fn (array $row): bool => ($row['Column_name'] ?? '') === 'business_reference',
        ));

        $this->assertGreaterThanOrEqual(2, count($businessRefIndexes));
    }

    public function test_november_migration_up_is_no_op_when_canonical_index_present_on_mariadb(): void
    {
        $pdo = $this->pdoOrSkip();
        $this->bootstrapLaravelMariaDbConnection($pdo);

        $migration = require database_path(
            'migrations/2026_11_21_170000_add_unique_business_reference_credit_to_central_wallet_ledger_entries.php',
        );

        $before = $pdo->query(
            "SHOW INDEX FROM central_wallet_ledger_entries WHERE Key_name = 'cw_ledger_business_ref_type_uq'",
        )->fetchAll(PDO::FETCH_ASSOC);

        $migration->up();
        $migration->up();

        $after = $pdo->query(
            "SHOW INDEX FROM central_wallet_ledger_entries WHERE Key_name = 'cw_ledger_business_ref_type_uq'",
        )->fetchAll(PDO::FETCH_ASSOC);

        $legacy = $pdo->query(
            "SHOW INDEX FROM central_wallet_ledger_entries WHERE Key_name = 'cw_ledger_business_ref_entry_type_uq'",
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($before);
        $this->assertSame($before, $after);
        $this->assertEmpty($legacy);
    }

    private function pdoOrSkip(): PDO
    {
        try {
            $pdo = new PDO(
                'mysql:host=127.0.0.1;port=33118',
                'root',
                '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped('Disposable MariaDB is not reachable on 127.0.0.1:33118: '.$exception->getMessage());
        }

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (! str_starts_with($version, '11.8.')) {
            $this->markTestSkipped('Expected MariaDB 11.8.x, got: '.$version);
        }

        return $pdo;
    }

    private function bootstrapLaravelMariaDbConnection(PDO $pdo): void
    {
        $pdo->exec('DROP DATABASE IF EXISTS `'.self::DATABASE.'`');
        $pdo->exec('CREATE DATABASE `'.self::DATABASE.'`');
        $pdo->exec('USE `'.self::DATABASE.'`');

        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '33118',
                'database' => self::DATABASE,
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
        ]);

        $foundation = require database_path('migrations/2026_09_28_100000_create_central_wallet_foundation_tables.php');
        $foundation->up();

        $october = require database_path(
            'migrations/2026_10_13_220000_add_unique_business_reference_to_central_wallet_ledger_entries.php',
        );
        $october->up();
    }
}
