<?php

namespace Tests\Feature\ServiceCaseAssignment;

use App\Services\ServiceCaseAssignmentService;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\ServiceCaseAssignment\Support\ServiceCaseAssignmentMysqlGate;
use Tests\TestCase;

class ServiceCaseAssignmentReadyQueueMysqlConcurrencyTest extends TestCase
{
    private const DEFAULT_MYSQL_PORT = '33118';

    private const DEFAULT_MYSQL_USER = 'rd_sc_assign_test';

    private const DEFAULT_MYSQL_PASSWORD = 'rd_sc_assign_test_pw';

    protected function tearDown(): void
    {
        DB::disconnect('mysql');

        parent::tearDown();
    }

    public function test_gate_allows_only_loopback_hosts_and_the_named_test_database(): void
    {
        $this->assertTrue(ServiceCaseAssignmentMysqlGate::isAllowedHost('127.0.0.1'));
        $this->assertTrue(ServiceCaseAssignmentMysqlGate::isAllowedDatabase(ServiceCaseAssignmentMysqlGate::DATABASE));
    }

    public function test_concurrent_ready_validation_preserves_manual_owner_and_one_audit(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, 8);

        foreach ($results as $row) {
            $this->assertTrue($row['ok'] ?? false, json_encode($row));
            $this->assertTrue($row['owner_preserved'] ?? false, json_encode($row));
        }

        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $incidentId = (int) $context['incident_id'];
        $agentId = (int) $context['agent_user_id'];

        $assigned = (int) $pdo->query(
            "select assigned_to_user_id from incidents where id = {$incidentId}",
        )->fetchColumn();
        $this->assertSame($agentId, $assigned);

        $auditCount = (int) $pdo->query(
            "select count(*) from audit_logs where event = '".ServiceCaseAssignmentService::READY_QUEUE_OWNER_PRESERVED_EVENT
            ."' and auditable_id = {$incidentId}",
        )->fetchColumn();
        $this->assertSame(1, $auditCount);
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(): array
    {
        $this->assertSafeMysqlTarget();
        $pdo = $this->probeSafeMysqlOrSkip();
        unset($pdo);
        DB::disconnect('mysql');

        $prepare = $this->process(
            [$this->phpBinary(), base_path('tests/Feature/ServiceCaseAssignment/Support/mysql_ready_queue_preserve_prepare.php')],
            300,
        );
        $prepare->mustRun();

        $output = trim($prepare->getOutput());
        $jsonLine = $output;
        if ($output !== '' && ! str_starts_with($output, '{')) {
            $lines = preg_split("/\r\n|\n|\r/", $output) ?: [];
            $candidates = array_values(array_filter(
                $lines,
                fn (string $line): bool => str_starts_with(ltrim($line), '{'),
            ));
            $jsonLine = $candidates === [] ? $output : (string) end($candidates);
        }
        $context = json_decode($jsonLine, true);
        $this->assertIsArray($context, 'Prepare did not return JSON. '.$output);

        return $context;
    }

    private function assertSafeMysqlTarget(): void
    {
        $host = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_DATABASE') ?: ServiceCaseAssignmentMysqlGate::DATABASE;

        $this->assertTrue(ServiceCaseAssignmentMysqlGate::isAllowedHost($host));
        $this->assertTrue(ServiceCaseAssignmentMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(ServiceCaseAssignmentMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT;
        $database = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_DATABASE') ?: ServiceCaseAssignmentMysqlGate::DATABASE;
        $username = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER;
        $password = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PASSWORD') !== false
            ? (string) getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PASSWORD')
            : self::DEFAULT_MYSQL_PASSWORD;

        if ($port === '' || $port === '3306') {
            $this->markTestSkipped(
                'MariaDB test environment unavailable → BLOCKED. Need disposable '.ServiceCaseAssignmentMysqlGate::DATABASE
                .' on loopback non-3306 port.'
            );
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException) {
            $this->markTestSkipped('Disposable MySQL/MariaDB test database is not reachable.');
        }

        return $pdo;
    }

    private function phpBinary(): string
    {
        $candidate = '/opt/homebrew/bin/php';

        return is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function runWorkers(array $payload, int $workers): array
    {
        $dir = sys_get_temp_dir().'/sc-assignment-mysql-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        $go = $dir.'/go';
        $processes = [];
        $resultPaths = [];

        for ($index = 0; $index < $workers; $index++) {
            $payloadPath = $dir.'/payload-'.$index.'.json';
            $readyPath = $dir.'/ready-'.$index;
            $resultPath = $dir.'/result-'.$index.'.json';
            $resultPaths[] = $resultPath;
            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = $this->process([
                $this->phpBinary(),
                base_path('tests/Feature/ServiceCaseAssignment/Support/mysql_ready_queue_preserve_worker.php'),
                '--payload='.$payloadPath,
                '--ready='.$readyPath,
                '--go='.$go,
                '--result='.$resultPath,
            ], 120);
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

        file_put_contents($go, '1');

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

    private function safePdo(): PDO
    {
        $host = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT;
        $database = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_DATABASE') ?: ServiceCaseAssignmentMysqlGate::DATABASE;
        $username = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER;
        $password = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PASSWORD') !== false
            ? (string) getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PASSWORD')
            : self::DEFAULT_MYSQL_PASSWORD;

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$database}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
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

        $host = (string) (getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT);
        $username = (string) (getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER);
        $password = getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PASSWORD') !== false
            ? (string) getenv('SERVICE_CASE_ASSIGNMENT_MYSQL_PASSWORD')
            : self::DEFAULT_MYSQL_PASSWORD;

        $env['SERVICE_CASE_ASSIGNMENT_MYSQL_HOST'] = $host;
        $env['SERVICE_CASE_ASSIGNMENT_MYSQL_PORT'] = $port;
        $env['SERVICE_CASE_ASSIGNMENT_MYSQL_DATABASE'] = ServiceCaseAssignmentMysqlGate::DATABASE;
        $env['SERVICE_CASE_ASSIGNMENT_MYSQL_USERNAME'] = $username;
        $env['SERVICE_CASE_ASSIGNMENT_MYSQL_PASSWORD'] = $password;
        $env['DB_CONNECTION'] = 'mysql';
        $env['DB_HOST'] = $host;
        $env['DB_PORT'] = $port;
        $env['DB_DATABASE'] = ServiceCaseAssignmentMysqlGate::DATABASE;
        $env['DB_USERNAME'] = $username;
        $env['DB_PASSWORD'] = $password;
        $env['DB_URL'] = '';
        $env['DB_SOCKET'] = '';
        $env['APP_ENV'] = 'testing';
        $env['CACHE_STORE'] = 'array';
        $env['SESSION_DRIVER'] = 'array';
        $env['QUEUE_CONNECTION'] = 'sync';

        return new Process($command, timeout: $timeout, env: $env);
    }
}
