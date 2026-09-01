<?php

namespace Tests\Feature\Inventory;

use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\Inventory\Support\InventoryPosMysqlGate;
use Tests\TestCase;

class InventoryPosMysqlConcurrencyTest extends TestCase
{
    public const SAFE_DATABASE = InventoryPosMysqlGate::DATABASE;

    public function test_gate_allows_only_loopback_hosts_and_the_named_test_database(): void
    {
        $this->assertTrue(InventoryPosMysqlGate::isAllowedHost('127.0.0.1'));
        $this->assertTrue(InventoryPosMysqlGate::isAllowedHost('localhost'));
        $this->assertTrue(InventoryPosMysqlGate::isAllowedHost('::1'));
        $this->assertFalse(InventoryPosMysqlGate::isAllowedHost('rdservice.in'));
        $this->assertFalse(InventoryPosMysqlGate::isAllowedHost('10.0.0.5'));
        $this->assertFalse(InventoryPosMysqlGate::isAllowedHost('radium_desk_local'));
        $this->assertFalse(InventoryPosMysqlGate::isAllowedHost('127.0.0.1;dbname=mysql'));
        $this->assertTrue(InventoryPosMysqlGate::isAllowedDatabase(self::SAFE_DATABASE));
        $this->assertFalse(InventoryPosMysqlGate::isAllowedDatabase('radium_desk_local'));
        $this->assertFalse(InventoryPosMysqlGate::isAllowedDatabase('radiumbox_prod'));
        $this->assertFalse(InventoryPosMysqlGate::isAllowedAppEnv('production'));
    }

    public function test_same_serial_two_independent_mysql_transactions_one_sale_wins(): void
    {
        $context = $this->prepareSafeMysqlContextOrSkip();

        $results = $this->runTwoWorkers($context, [
            ['serial' => $context['contended_serial'], 'phone' => '9111100101', 'name' => 'Counter A'],
            ['serial' => $context['contended_serial'], 'phone' => '9111100102', 'name' => 'Counter B'],
        ]);

        $wins = array_values(array_filter($results, fn (array $row): bool => $row['ok'] === true));
        $losses = array_values(array_filter($results, fn (array $row): bool => $row['ok'] !== true));

        $this->assertCount(1, $wins, 'Exactly one concurrent sale of the same serial must succeed. Results: '.json_encode($results));
        $this->assertCount(1, $losses, 'The losing attempt must fail closed. Results: '.json_encode($results));
        $this->assertNotSame(
            $results[0]['connection_id'] ?? null,
            $results[1]['connection_id'] ?? null,
            'Workers must use independent MySQL connections.'
        );

        $pdo = $this->safePdo();
        $saleCount = (int) $pdo->query('select count(*) from inventory_sales')->fetchColumn();
        $sold = (int) $pdo->query("select count(*) from inventory_serials where serial_number = 'INNODB-SAME-1' and status = 'sold'")->fetchColumn();
        $available = (int) $pdo->query("select count(*) from inventory_serials where serial_number = 'INNODB-SAME-1' and status = 'available'")->fetchColumn();
        $saleMovements = (int) $pdo->query("select count(*) from inventory_movements where type = 'sale' and serial_id = (select id from inventory_serials where serial_number = 'INNODB-SAME-1')")->fetchColumn();
        $invoices = (int) $pdo->query('select count(distinct invoice_number) from inventory_sales')->fetchColumn();
        $journals = (int) $pdo->query("select count(*) from finance_journals where source_type = 'pos_sale'")->fetchColumn();
        $negative = (int) $pdo->query('select count(*) from inventory_stock_balances where available_qty < 0 or reserved_qty < 0')->fetchColumn();

        $this->assertSame(1, $saleCount);
        $this->assertSame(1, $sold);
        $this->assertSame(0, $available);
        $this->assertSame(1, $saleMovements);
        $this->assertSame(1, $invoices);
        $this->assertSame(1, $journals);
        $this->assertSame(0, $negative);
        $this->assertNotEmpty($losses[0]['error'] ?? null);
    }

    public function test_different_serials_complete_on_independent_connections(): void
    {
        $context = $this->prepareSafeMysqlContextOrSkip();

        $results = $this->runTwoWorkers($context, [
            ['serial' => $context['independent_serial_a'], 'phone' => '9111100201', 'name' => 'Independent A'],
            ['serial' => $context['independent_serial_b'], 'phone' => '9111100202', 'name' => 'Independent B'],
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], 'Independent serials must both complete. Results: '.json_encode($results));
        $this->assertNotSame($results[0]['sale_id'], $results[1]['sale_id']);
        $this->assertNotSame($results[0]['invoice_number'], $results[1]['invoice_number']);
        $this->assertNotSame($results[0]['connection_id'] ?? null, $results[1]['connection_id'] ?? null);

        $pdo = $this->safePdo();
        $soldA = (int) $pdo->query("select count(*) from inventory_serials where serial_number = 'INNODB-IND-A' and status = 'sold'")->fetchColumn();
        $soldB = (int) $pdo->query("select count(*) from inventory_serials where serial_number = 'INNODB-IND-B' and status = 'sold'")->fetchColumn();
        $this->assertSame(1, $soldA);
        $this->assertSame(1, $soldB);
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareSafeMysqlContextOrSkip(): array
    {
        $this->assertSafeMysqlTarget();
        $pdo = $this->probeSafeMysqlOrSkip();

        $this->assertSame(self::SAFE_DATABASE, (string) $pdo->query('select database()')->fetchColumn());

        $prepare = $this->process(
            [PHP_BINARY, base_path('tests/Feature/Inventory/Support/mysql_pos_prepare.php')],
            120
        );
        $prepare->mustRun();

        $context = json_decode(trim($prepare->getOutput()), true);
        $this->assertIsArray($context);
        $this->assertArrayHasKey('contended_serial', $context);

        return $context;
    }

    private function assertSafeMysqlTarget(): void
    {
        $host = getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('INVENTORY_POS_MYSQL_DATABASE') ?: self::SAFE_DATABASE;

        $this->assertTrue(
            InventoryPosMysqlGate::isAllowedHost($host),
            'Refusing MySQL host '.$host.'. Loopback only.'
        );
        $this->assertTrue(
            InventoryPosMysqlGate::isAllowedDatabase($database),
            'Refusing MySQL database '.$database.'. Allowed: '.self::SAFE_DATABASE
        );
        $this->assertTrue(
            InventoryPosMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')),
            'Refusing to run MySQL inventory helpers outside local/testing.'
        );
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('INVENTORY_POS_MYSQL_PORT') ?: '3306';
        $database = getenv('INVENTORY_POS_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('INVENTORY_POS_MYSQL_USERNAME') ?: '';
        $password = getenv('INVENTORY_POS_MYSQL_PASSWORD') !== false ? (string) getenv('INVENTORY_POS_MYSQL_PASSWORD') : '';

        if ($username === '') {
            $this->markTestSkipped(
                'MySQL test environment unavailable → UNKNOWN/BLOCKER. No mysqld on loopback and no dedicated '.
                self::SAFE_DATABASE.' user. This suite does not start brew MySQL, does not use radium_desk_local, '.
                'and does not touch production. When a local server exists: create only '.self::SAFE_DATABASE.
                ' on 127.0.0.1 and run with INVENTORY_POS_MYSQL_USERNAME set.'
            );
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped(
                'MySQL test environment unavailable → UNKNOWN/BLOCKER. '.
                self::SAFE_DATABASE.' on '.$host.':'.$port.' is not reachable ('.$exception->getMessage().'). '.
                'Production MySQL was not used.'
            );
        }

        return $pdo;
    }

    private function safePdo(): PDO
    {
        return $this->probeSafeMysqlOrSkip();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array{serial: string, phone: string, name: string}>  $attempts
     * @return list<array<string, mixed>>
     */
    private function runTwoWorkers(array $context, array $attempts): array
    {
        $dir = sys_get_temp_dir().'/inventory-pos-mysql-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        $go = $dir.'/go';
        $processes = [];
        $resultPaths = [];

        foreach ($attempts as $index => $attempt) {
            $payloadPath = $dir.'/payload-'.$index.'.json';
            $readyPath = $dir.'/ready-'.$index;
            $resultPath = $dir.'/result-'.$index.'.json';
            $resultPaths[] = $resultPath;

            file_put_contents($payloadPath, json_encode([
                'actor_id' => $context['actor_id'],
                'branch_id' => $context['branch_id'],
                'product_id' => $context['serialized_product_id'],
                'serial' => $attempt['serial'],
                'customer_name' => $attempt['name'],
                'customer_phone' => $attempt['phone'],
                'idempotency_key' => 'innodb-'.$index.'-'.bin2hex(random_bytes(4)),
            ], JSON_THROW_ON_ERROR));

            $process = $this->process([
                PHP_BINARY,
                base_path('tests/Feature/Inventory/Support/mysql_pos_sale_worker.php'),
                '--payload='.$payloadPath,
                '--ready='.$readyPath,
                '--go='.$go,
                '--result='.$resultPath,
            ], 60);
            $process->start();
            $processes[] = ['process' => $process, 'ready' => $readyPath];
        }

        $deadline = microtime(true) + 20;
        foreach ($processes as $item) {
            while (! is_file($item['ready'])) {
                if (microtime(true) > $deadline) {
                    foreach ($processes as $running) {
                        $running['process']->stop(0.2);
                    }
                    $this->fail('Workers did not reach the InnoDB start barrier.');
                }
                usleep(5000);
            }
        }

        file_put_contents($go, '1');

        foreach ($processes as $item) {
            $item['process']->wait();
        }

        $results = [];
        foreach ($resultPaths as $path) {
            $this->assertFileExists($path, 'Worker did not write a result file.');
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($decoded);
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @param  list<string>  $command
     */
    private function process(array $command, int $timeout): Process
    {
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }

        $env['DB_CONNECTION'] = 'mysql';
        $env['DB_HOST'] = getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1';
        $env['DB_PORT'] = getenv('INVENTORY_POS_MYSQL_PORT') ?: '3306';
        $env['DB_DATABASE'] = self::SAFE_DATABASE;
        $env['DB_USERNAME'] = (string) getenv('INVENTORY_POS_MYSQL_USERNAME');
        $env['DB_PASSWORD'] = getenv('INVENTORY_POS_MYSQL_PASSWORD') !== false ? (string) getenv('INVENTORY_POS_MYSQL_PASSWORD') : '';
        $env['DB_URL'] = '';
        $env['DB_SOCKET'] = '';
        $env['APP_ENV'] = 'testing';
        $env['CACHE_STORE'] = 'array';
        $env['SESSION_DRIVER'] = 'array';
        $env['QUEUE_CONNECTION'] = 'sync';

        return new Process($command, base_path(), $env, null, $timeout);
    }
}
