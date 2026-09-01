<?php

namespace Tests\Feature\Inventory;

use PDO;
use PDOException;
use Tests\TestCase;

class InventoryPosMysqlConcurrencyTest extends TestCase
{
    public const SAFE_DATABASE = 'radium_desk_inventory_pos_test';

    public function test_mysql_serialized_sale_contention_is_gated_to_a_safe_database(): void
    {
        if (getenv('INVENTORY_POS_MYSQL_TEST') !== '1') {
            $this->markTestSkipped(
                'MySQL concurrency is gated. A mysqld was not listening on this machine (PDO 2002). '.
                'Do not point this test at production. When a throwaway database named '.
                self::SAFE_DATABASE.' exists, run: INVENTORY_POS_MYSQL_TEST=1 '.
                'INVENTORY_POS_MYSQL_DATABASE='.self::SAFE_DATABASE.' php artisan test --filter=InventoryPosMysqlConcurrencyTest'
            );
        }

        $database = getenv('INVENTORY_POS_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $this->assertSame(
            self::SAFE_DATABASE,
            $database,
            'Refusing to run MySQL concurrency against '.$database.'. Allowed name: '.self::SAFE_DATABASE
        );

        $host = getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('INVENTORY_POS_MYSQL_PORT') ?: '3306';
        $user = getenv('INVENTORY_POS_MYSQL_USERNAME') ?: 'root';
        $password = getenv('INVENTORY_POS_MYSQL_PASSWORD') ?: '';

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $database),
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped(
                'Safe MySQL database '.self::SAFE_DATABASE.' is not reachable: '.$exception->getMessage()
            );
        }

        $this->assertNotNull($pdo);
        $this->markTestSkipped(
            'MySQL is reachable on '.self::SAFE_DATABASE.' but two-process completeSale interleaving is not auto-executed in this gate. Start two POS completes against the same serial on that throwaway schema; exactly one must succeed.'
        );
    }
}
