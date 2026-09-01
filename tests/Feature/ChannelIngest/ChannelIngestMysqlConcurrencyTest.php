<?php

namespace Tests\Feature\ChannelIngest;

use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\Inventory\Support\InventoryPosMysqlGate;
use Tests\TestCase;

class ChannelIngestMysqlConcurrencyTest extends TestCase
{
    public const SAFE_DATABASE = InventoryPosMysqlGate::DATABASE;

    public function test_gate_reuses_the_established_loopback_inventory_pos_harness(): void
    {
        $this->assertTrue(InventoryPosMysqlGate::isAllowedHost('127.0.0.1'));
        $this->assertTrue(InventoryPosMysqlGate::isAllowedDatabase(self::SAFE_DATABASE));
        $this->assertFalse(InventoryPosMysqlGate::isAllowedDatabase('radiumbox_prod'));
    }

    public function test_two_connections_ingesting_the_same_source_create_one_order_and_no_invoice(): void
    {
        $this->prepareSafeMysqlContextOrSkip();

        $results = $this->runTwoWorkers([
            ['source_id' => 'same-source', 'channel' => 'rdservice_in'],
            ['source_id' => 'same-source', 'channel' => 'rdservice_in'],
        ]);

        $wins = array_values(array_filter($results, fn (array $row): bool => $row['ok'] === true));
        $this->assertGreaterThanOrEqual(1, count($wins), 'At least one ingest must succeed. Results: '.json_encode($results));
        $this->assertNotSame($results[0]['connection_id'] ?? null, $results[1]['connection_id'] ?? null);

        $pdo = $this->safePdo();
        $orders = (int) $pdo->query('select count(*) from commerce_orders')->fetchColumn();
        $invoices = (int) $pdo->query('select count(*) from statutory_invoices')->fetchColumn();
        $journals = (int) $pdo->query('select count(*) from finance_journals')->fetchColumn();

        $this->assertSame(1, $orders);
        $this->assertSame(0, $invoices);
        $this->assertSame(0, $journals);
        if (count($wins) === 2) {
            $this->assertSame($wins[0]['order_id'], $wins[1]['order_id']);
        }
    }

    private function prepareSafeMysqlContextOrSkip(): void
    {
        $host = getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('INVENTORY_POS_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $this->assertTrue(InventoryPosMysqlGate::isAllowedHost($host));
        $this->assertTrue(InventoryPosMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(InventoryPosMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
        $this->probeSafeMysqlOrSkip();

        $prepare = $this->process(
            [PHP_BINARY, base_path('tests/Feature/StatutoryInvoice/Support/mysql_statutory_prepare.php')],
            180,
        );
        $prepare->mustRun();
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('INVENTORY_POS_MYSQL_PORT') ?: '';
        $database = getenv('INVENTORY_POS_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('INVENTORY_POS_MYSQL_USERNAME') ?: '';
        $password = getenv('INVENTORY_POS_MYSQL_PASSWORD') !== false ? (string) getenv('INVENTORY_POS_MYSQL_PASSWORD') : '';

        if ($username === '' || $port === '' || $port === '3306') {
            $this->markTestSkipped(
                'MySQL test environment unavailable → UNKNOWN/BLOCKER. Reuses the disposable '.
                self::SAFE_DATABASE.' harness. Production MySQL was not used.'
            );
        }

        try {
            return new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped(
                'MySQL test environment unavailable → UNKNOWN/BLOCKER. '.
                $exception->getMessage()
            );
        }
    }

    private function safePdo(): PDO
    {
        return $this->probeSafeMysqlOrSkip();
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @return list<array<string, mixed>>
     */
    private function runTwoWorkers(array $attempts): array
    {
        $dir = sys_get_temp_dir().'/channel-ingest-mysql-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $go = $dir.'/go';
        $processes = [];
        $resultPaths = [];

        foreach ($attempts as $index => $attempt) {
            $payloadPath = $dir.'/payload-'.$index.'.json';
            $readyPath = $dir.'/ready-'.$index;
            $resultPath = $dir.'/result-'.$index.'.json';
            $resultPaths[] = $resultPath;
            file_put_contents($payloadPath, json_encode($attempt, JSON_THROW_ON_ERROR));

            $process = $this->process([
                PHP_BINARY,
                base_path('tests/Feature/ChannelIngest/Support/mysql_channel_ingest_worker.php'),
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
                    $this->fail('Worker did not become ready.');
                }
                usleep(5000);
            }
        }

        file_put_contents($go, '1');

        $results = [];
        foreach ($processes as $index => $item) {
            $item['process']->wait();
            $raw = is_file($resultPaths[$index]) ? (string) file_get_contents($resultPaths[$index]) : '{}';
            $decoded = json_decode($raw, true);
            $results[] = is_array($decoded) ? $decoded : ['ok' => false, 'error' => $raw];
        }

        return $results;
    }

    /**
     * @param  list<string>  $command
     */
    private function process(array $command, int $timeout): Process
    {
        $process = new Process($command, base_path(), [
            'INVENTORY_POS_MYSQL_HOST' => getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1',
            'INVENTORY_POS_MYSQL_PORT' => getenv('INVENTORY_POS_MYSQL_PORT') ?: '',
            'INVENTORY_POS_MYSQL_DATABASE' => getenv('INVENTORY_POS_MYSQL_DATABASE') ?: self::SAFE_DATABASE,
            'INVENTORY_POS_MYSQL_USERNAME' => getenv('INVENTORY_POS_MYSQL_USERNAME') ?: '',
            'INVENTORY_POS_MYSQL_PASSWORD' => getenv('INVENTORY_POS_MYSQL_PASSWORD') !== false ? (string) getenv('INVENTORY_POS_MYSQL_PASSWORD') : '',
            'APP_ENV' => 'testing',
        ]);
        $process->setTimeout($timeout);

        return $process;
    }
}
