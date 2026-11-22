<?php

namespace Tests\Feature\PreShipmentCancellation;

use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\PreShipmentCancellation\Support\PreShipmentCancellationMariaDbGate;
use Tests\TestCase;

class PreShipmentCancellationIngestRaceMysqlConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_simultaneous_cancel_and_ingest_never_produce_duplicate_fulfilment(): void
    {
        $context = $this->prepareContextOrSkip();
        $payload = $context;

        $results = $this->runParallelWorkers([
            ['role' => 'cancel', 'prelock' => false],
            ['role' => 'ingest', 'prelock' => false],
        ], $payload);

        $this->assertIndependentConnections($results);
        $this->assertFinalRaceInvariants($context);
    }

    public function test_ingest_prelock_scenario_bounded_under_innodb(): void
    {
        $context = $this->prepareContextOrSkip();
        $payload = $context;

        $results = $this->runParallelWorkers([
            ['role' => 'ingest', 'prelock' => true],
            ['role' => 'cancel', 'prelock' => false],
        ], $payload);

        $this->assertIndependentConnections($results);
        $this->assertFinalRaceInvariants($context);
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(): array
    {
        $this->assertSafeMysqlTarget();
        $this->probeSafeMysqlOrSkip();

        $prepare = new Process(
            [PHP_BINARY, base_path('tests/Feature/PreShipmentCancellation/Support/mysql_preship_race_prepare.php')],
            base_path(),
            $this->workerEnv(),
            null,
            600,
        );
        $prepare->mustRun();

        $output = trim($prepare->getOutput());
        $jsonLine = str_starts_with($output, '{') ? $output : (string) preg_replace('/^[^\{]*/', '', $output);
        $context = json_decode($jsonLine, true);
        $this->assertIsArray($context);

        return $context;
    }

    /**
     * @param  list<array{role: string, prelock: bool}>  $workers
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function runParallelWorkers(array $workers, array $payload): array
    {
        $dir = sys_get_temp_dir().'/psc-race-'.bin2hex(random_bytes(5));
        mkdir($dir, 0700);
        $goPath = $dir.'/go';

        $processes = [];
        $resultPaths = [];

        foreach ($workers as $index => $worker) {
            $payloadPath = $dir.'/payload-'.$index.'.json';
            $readyPath = $dir.'/ready-'.$index;
            $resultPath = $dir.'/result-'.$index.'.json';
            $resultPaths[] = $resultPath;
            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $script = $worker['role'] === 'cancel'
                ? 'mysql_preship_cancel_worker.php'
                : 'mysql_preship_ingest_worker.php';

            $command = [
                PHP_BINARY,
                base_path('tests/Feature/PreShipmentCancellation/Support/'.$script),
                '--payload='.$payloadPath,
                '--ready='.$readyPath,
                '--go='.$goPath,
                '--result='.$resultPath,
            ];
            if ($worker['prelock'] && $worker['role'] === 'ingest') {
                $command[] = '--prelock';
            }

            $process = new Process($command, base_path(), $this->workerEnv(), null, 180);
            $process->start();
            $processes[] = ['process' => $process, 'ready' => $readyPath];
        }

        $deadline = microtime(true) + 90;
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

        file_put_contents($goPath, '1');

        foreach ($processes as $item) {
            $item['process']->wait();
        }

        $results = [];
        foreach ($resultPaths as $path) {
            $this->assertFileExists($path);
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($decoded);
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertFinalRaceInvariants(array $context): void
    {
        $pdo = $this->safePdo();

        $hfCount = (int) $pdo->query('select count(*) from hardware_fulfilments')->fetchColumn();
        $cancelCount = (int) $pdo->query('select count(*) from pre_shipment_cancellation_requests')->fetchColumn();
        $refundCount = (int) $pdo->query('select count(*) from refund_requests')->fetchColumn();

        $this->assertLessThanOrEqual(1, $hfCount);
        $this->assertLessThanOrEqual(1, $cancelCount);
        $this->assertSame(0, $refundCount);

        $statement = $pdo->prepare(
            'select hardware_fulfilment_blocked_at from commerce_orders where id = ?'
        );
        $statement->execute([(int) $context['commerce_order_id']]);
        $blockedAt = $statement->fetchColumn();

        if ($cancelCount === 1) {
            $this->assertNotFalse($blockedAt, 'Committed cancellation must block commerce ingestion.');
            $this->assertSame(0, $hfCount, 'No fulfilment row may exist after committed State A cancellation.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertIndependentConnections(array $results): void
    {
        $ids = array_values(array_filter(array_map(
            fn (array $row): ?int => isset($row['connection_id']) ? (int) $row['connection_id'] : null,
            $results,
        )));
        if ($ids !== []) {
            $this->assertGreaterThanOrEqual(2, count(array_unique($ids)), json_encode($results));
        }
    }

    private function assertSafeMysqlTarget(): void
    {
        $host = getenv('PRE_SHIPMENT_MARIADB_HOST') ?: '127.0.0.1';
        $database = getenv('PRE_SHIPMENT_MARIADB_DATABASE') ?: PreShipmentCancellationMariaDbGate::DATABASE;

        $this->assertTrue(PreShipmentCancellationMariaDbGate::isAllowedHost($host));
        $this->assertTrue(PreShipmentCancellationMariaDbGate::isAllowedDatabase($database));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('PRE_SHIPMENT_MARIADB_HOST') ?: '127.0.0.1';
        $port = getenv('PRE_SHIPMENT_MARIADB_PORT') ?: '33118';
        $database = getenv('PRE_SHIPMENT_MARIADB_DATABASE') ?: PreShipmentCancellationMariaDbGate::DATABASE;
        $username = getenv('PRE_SHIPMENT_MARIADB_USERNAME') ?: 'rd_preship_test';
        $password = getenv('PRE_SHIPMENT_MARIADB_PASSWORD') !== false
            ? (string) getenv('PRE_SHIPMENT_MARIADB_PASSWORD')
            : 'rd_preship_test_pw';

        if ($port === '' || $port === '3306') {
            $this->markTestSkipped('Disposable MariaDB port not configured for pre-shipment concurrency suite.');
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException) {
            $this->markTestSkipped('MariaDB concurrency database unreachable.');
        }

        return $pdo;
    }

    private function safePdo(): PDO
    {
        $host = getenv('PRE_SHIPMENT_MARIADB_HOST') ?: '127.0.0.1';
        $port = getenv('PRE_SHIPMENT_MARIADB_PORT') ?: '33118';
        $database = getenv('PRE_SHIPMENT_MARIADB_DATABASE') ?: PreShipmentCancellationMariaDbGate::DATABASE;
        $username = getenv('PRE_SHIPMENT_MARIADB_USERNAME') ?: 'rd_preship_test';
        $password = getenv('PRE_SHIPMENT_MARIADB_PASSWORD') !== false
            ? (string) getenv('PRE_SHIPMENT_MARIADB_PASSWORD')
            : 'rd_preship_test_pw';

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$database}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * @return array<string, string>
     */
    private function workerEnv(): array
    {
        $host = getenv('PRE_SHIPMENT_MARIADB_HOST') ?: '127.0.0.1';
        $port = getenv('PRE_SHIPMENT_MARIADB_PORT') ?: '33118';
        $database = getenv('PRE_SHIPMENT_MARIADB_DATABASE') ?: PreShipmentCancellationMariaDbGate::DATABASE;
        $username = getenv('PRE_SHIPMENT_MARIADB_USERNAME') ?: 'rd_preship_test';
        $password = getenv('PRE_SHIPMENT_MARIADB_PASSWORD') !== false
            ? (string) getenv('PRE_SHIPMENT_MARIADB_PASSWORD')
            : 'rd_preship_test_pw';

        return [
            'APP_ENV' => 'testing',
            'PRE_SHIPMENT_MARIADB_HOST' => $host,
            'PRE_SHIPMENT_MARIADB_PORT' => $port,
            'PRE_SHIPMENT_MARIADB_DATABASE' => $database,
            'PRE_SHIPMENT_MARIADB_USERNAME' => $username,
            'PRE_SHIPMENT_MARIADB_PASSWORD' => $password,
            'PRE_SHIPMENT_CANCELLATION_ENABLED' => 'true',
        ];
    }
}
