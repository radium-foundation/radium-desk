<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Services\PreShipmentCancellation\PreShipmentCancellationIncidentService;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\PreShipmentCancellation\Support\PreShipmentCancellationMariaDbGate;
use Tests\TestCase;

class PreShipmentCancellationIncidentRetryMysqlConcurrencyTest extends TestCase
{
    public function test_parallel_incident_retry_workers_produce_single_audit_note(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runParallelWorkers($context, 2);

        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $auditStatement = $pdo->prepare('select count(*) from audit_logs where event = ?');
        $auditStatement->execute([PreShipmentCancellationIncidentService::AUDIT_HARDWARE_PRESHIP_CANCELLED]);
        $auditCount = (int) $auditStatement->fetchColumn();
        $this->assertSame(1, $auditCount);

        $statement = $pdo->prepare('select status from incidents where id = ?');
        $statement->execute([(int) $context['incident_id']]);
        $this->assertSame('closed', $statement->fetchColumn());

        $statement = $pdo->prepare('select service_case_sync_status from pre_shipment_cancellation_requests where id = ?');
        $statement->execute([(int) $context['case_id']]);
        $this->assertSame('succeeded', $statement->fetchColumn());

        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($result));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(): array
    {
        $this->assertSafeMysqlTarget();
        $this->probeSafeMysqlOrSkip();

        $prepare = new Process(
            [PHP_BINARY, base_path('tests/Feature/PreShipmentCancellation/Support/mysql_preship_incident_retry_prepare.php')],
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
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function runParallelWorkers(array $payload, int $workerCount): array
    {
        $dir = sys_get_temp_dir().'/psc-incident-'.bin2hex(random_bytes(5));
        mkdir($dir, 0700);
        $goPath = $dir.'/go';

        $processes = [];
        $resultPaths = [];

        for ($index = 0; $index < $workerCount; $index++) {
            $payloadPath = $dir.'/payload-'.$index.'.json';
            $readyPath = $dir.'/ready-'.$index;
            $resultPath = $dir.'/result-'.$index.'.json';
            $resultPaths[] = $resultPath;
            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = new Process(
                [
                    PHP_BINARY,
                    base_path('tests/Feature/PreShipmentCancellation/Support/mysql_preship_incident_close_worker.php'),
                    '--payload='.$payloadPath,
                    '--ready='.$readyPath,
                    '--go='.$goPath,
                    '--result='.$resultPath,
                ],
                base_path(),
                $this->workerEnv(),
                null,
                180,
            );
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
            $this->assertTrue(
                $item['process']->isSuccessful(),
                $item['process']->getErrorOutput().$item['process']->getOutput()
            );
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
