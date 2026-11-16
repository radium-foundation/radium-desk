<?php

namespace Tests\Feature\HardwareFulfilment;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\HardwareFulfilment\Support\HardwareFulfilmentMysqlGate;
use Tests\TestCase;

class HardwareRbpChannelIngestMysqlConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::disconnect('mysql');

        parent::tearDown();
    }

    private const DEFAULT_MYSQL_PORT = '33118';

    private const DEFAULT_MYSQL_USER = 'rd_hw_fulf_test';

    private const DEFAULT_MYSQL_PASSWORD = 'rd_hw_fulf_test_pw';

    public function test_gate_allows_only_loopback_hosts_and_the_named_test_database(): void
    {
        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedHost('127.0.0.1'));
        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedDatabase(HardwareFulfilmentMysqlGate::DATABASE));
    }

    #[Group('permanent_regression')]
    public function test_concurrent_unpaid_rbp_channel_ingest_creates_one_commerce_without_fulfilment(): void
    {
        $context = $this->prepareContextOrSkip(
            base_path('tests/Feature/HardwareFulfilment/Support/mysql_rbp_channel_ingest_unpaid_prepare.php'),
        );

        $workerPayload = ['ingest_payload' => $context['ingest_payload']];
        $results = $this->runWorkers($workerPayload, 6);

        $ok = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertGreaterThanOrEqual(1, count($ok), json_encode($results));

        $commerceCounts = array_unique(array_map(fn (array $row): int => (int) ($row['commerce_count'] ?? -1), $results));
        $fulfilmentCounts = array_unique(array_map(fn (array $row): int => (int) ($row['fulfilment_count'] ?? -1), $results));

        $this->assertSame([1], $commerceCounts, json_encode($results));
        $this->assertSame([0], $fulfilmentCounts, json_encode($results));
        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $this->assertSame(1, (int) $pdo->query('select count(*) from commerce_orders')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('select count(*) from hardware_fulfilments')->fetchColumn());
        $this->assertSame(
            0,
            (int) $pdo->query("select count(*) from hardware_fulfilments where state = 'ready_for_fulfilment'")->fetchColumn(),
        );
    }

    public function test_concurrent_rbp_channel_ingest_creates_one_commerce_and_one_fulfilment(): void
    {
        $context = $this->prepareContextOrSkip();

        $workerPayload = ['ingest_payload' => $context['ingest_payload']];
        $results = $this->runWorkers($workerPayload, 6);

        $ok = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertGreaterThanOrEqual(1, count($ok), json_encode($results));

        $commerceCounts = array_unique(array_map(fn (array $row): int => (int) ($row['commerce_count'] ?? -1), $results));
        $fulfilmentCounts = array_unique(array_map(fn (array $row): int => (int) ($row['fulfilment_count'] ?? -1), $results));

        $this->assertSame([1], $commerceCounts, json_encode($results));
        $this->assertSame([1], $fulfilmentCounts, json_encode($results));
        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $this->assertSame(1, (int) $pdo->query('select count(*) from commerce_orders')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('select count(*) from hardware_fulfilments')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('select count(*) from hardware_fulfilment_serials where allocated_at is not null')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('select count(*) from inventory_stock_balances where reserved_qty > 0')->fetchColumn());
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(
        ?string $prepareScript = null,
    ): array {
        $this->assertSafeMysqlTarget();
        $pdo = $this->probeSafeMysqlOrSkip();
        unset($pdo);
        DB::disconnect('mysql');

        $prepare = $this->process(
            [$this->phpBinary(), $prepareScript ?? base_path('tests/Feature/HardwareFulfilment/Support/mysql_rbp_channel_ingest_prepare.php')],
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
        $host = getenv('HARDWARE_FULFILMENT_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('HARDWARE_FULFILMENT_MYSQL_DATABASE') ?: HardwareFulfilmentMysqlGate::DATABASE;

        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedHost($host));
        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('HARDWARE_FULFILMENT_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('HARDWARE_FULFILMENT_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT;
        $database = getenv('HARDWARE_FULFILMENT_MYSQL_DATABASE') ?: HardwareFulfilmentMysqlGate::DATABASE;
        $username = getenv('HARDWARE_FULFILMENT_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER;
        $password = getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD') !== false
            ? (string) getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD')
            : self::DEFAULT_MYSQL_PASSWORD;

        if ($port === '' || $port === '3306') {
            $this->markTestSkipped(
                'MariaDB test environment unavailable → BLOCKED. Need disposable '.HardwareFulfilmentMysqlGate::DATABASE
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
        $dir = sys_get_temp_dir().'/rbp-ingest-mysql-'.bin2hex(random_bytes(6));
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
                base_path('tests/Feature/HardwareFulfilment/Support/mysql_rbp_channel_ingest_worker.php'),
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
        $host = getenv('HARDWARE_FULFILMENT_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('HARDWARE_FULFILMENT_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT;
        $database = getenv('HARDWARE_FULFILMENT_MYSQL_DATABASE') ?: HardwareFulfilmentMysqlGate::DATABASE;
        $username = getenv('HARDWARE_FULFILMENT_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER;
        $password = getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD') !== false
            ? (string) getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD')
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

        $host = (string) (getenv('HARDWARE_FULFILMENT_MYSQL_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('HARDWARE_FULFILMENT_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT);
        $username = (string) (getenv('HARDWARE_FULFILMENT_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER);
        $password = getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD') !== false
            ? (string) getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD')
            : self::DEFAULT_MYSQL_PASSWORD;

        $env['HARDWARE_FULFILMENT_MYSQL_HOST'] = $host;
        $env['HARDWARE_FULFILMENT_MYSQL_PORT'] = $port;
        $env['HARDWARE_FULFILMENT_MYSQL_DATABASE'] = HardwareFulfilmentMysqlGate::DATABASE;
        $env['HARDWARE_FULFILMENT_MYSQL_USERNAME'] = $username;
        $env['HARDWARE_FULFILMENT_MYSQL_PASSWORD'] = $password;
        $env['DB_CONNECTION'] = 'mysql';
        $env['DB_HOST'] = $host;
        $env['DB_PORT'] = $port;
        $env['DB_DATABASE'] = HardwareFulfilmentMysqlGate::DATABASE;
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
