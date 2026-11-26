<?php

namespace Tests\Feature\CentralWallet;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\CentralWallet\Support\P01_60ReservationMysqlGate;
use Tests\TestCase;

#[Group('permanent_regression')]
final class P01_60VerifiedEmailSpendMysqlConcurrencyTest extends TestCase
{
    public const SAFE_DATABASE = P01_60ReservationMysqlGate::DATABASE;

    private const DEFAULT_MYSQL_PORT = '33118';

    private const DEFAULT_MYSQL_USER = 'rd_p01_60_reservation_test';

    private const DEFAULT_MYSQL_PASSWORD = 'rd_p01_60_reservation_test_pw';

    protected function setUp(): void
    {
        parent::setUp();

        P01_60ReservationMysqlGate::forceProcessEnv([
            'P01_60_RESERVATION_MYSQL_HOST' => getenv('P01_60_RESERVATION_MYSQL_HOST') ?: '127.0.0.1',
            'P01_60_RESERVATION_MYSQL_PORT' => getenv('P01_60_RESERVATION_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT,
            'P01_60_RESERVATION_MYSQL_DATABASE' => getenv('P01_60_RESERVATION_MYSQL_DATABASE') ?: self::SAFE_DATABASE,
            'P01_60_RESERVATION_MYSQL_USERNAME' => getenv('P01_60_RESERVATION_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER,
            'P01_60_RESERVATION_MYSQL_PASSWORD' => getenv('P01_60_RESERVATION_MYSQL_PASSWORD') !== false
                ? (string) getenv('P01_60_RESERVATION_MYSQL_PASSWORD')
                : self::DEFAULT_MYSQL_PASSWORD,
            'APP_ENV' => 'testing',
        ]);
    }

    private function phpBinary(): string
    {
        $candidate = '/opt/homebrew/bin/php';

        return is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    public function test_gate_allows_only_loopback_hosts_and_named_database(): void
    {
        $this->assertTrue(P01_60ReservationMysqlGate::isAllowedHost('127.0.0.1'));
        $this->assertFalse(P01_60ReservationMysqlGate::isAllowedHost('187.127.129.16'));
        $this->assertTrue(P01_60ReservationMysqlGate::isAllowedDatabase(self::SAFE_DATABASE));
        $this->assertFalse(P01_60ReservationMysqlGate::isAllowedDatabase('radium_desk'));
    }

    public function test_two_workers_competing_p01_60_reservations_single_winner_at_boundary(): void
    {
        for ($run = 1; $run <= 3; $run++) {
            $context = $this->prepareContextOrSkip('competing_reserve', 'cr'.$run);
            $results = $this->runWorkers($context, 2);
            $this->assertCompetingReserveOutcomes($context, $results);
        }
    }

    public function test_two_workers_concurrent_commit_single_debit_for_p01_60_reservation(): void
    {
        for ($run = 1; $run <= 3; $run++) {
            $context = $this->prepareContextOrSkip('concurrent_commit', 'cc'.$run);
            $results = $this->runWorkers($context, 2);
            $this->assertConcurrentCommitOutcomes($context, $results);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(string $scenario, string $suffix): array
    {
        $this->assertSafeMysqlTarget();
        $this->probeSafeMysqlOrSkip();

        $prepare = $this->process([
            $this->phpBinary(),
            base_path('tests/Feature/CentralWallet/Support/mysql_p01_60_reservation_prepare.php'),
            '--scenario='.$scenario,
            '--suffix='.$suffix,
        ], 600);
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

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function runWorkers(array $context, int $workerCount): array
    {
        $dir = sys_get_temp_dir().'/p01-60-reservation-mysql-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        $go = $dir.'/go';
        $processes = [];
        $resultPaths = [];

        for ($index = 0; $index < $workerCount; $index++) {
            $payloadPath = $dir.'/payload-'.$index.'.json';
            $readyPath = $dir.'/ready-'.$index;
            $resultPath = $dir.'/result-'.$index.'.json';
            $resultPaths[] = $resultPath;

            $payload = array_merge($context, [
                'worker_label' => 'w'.$index,
            ]);
            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = $this->process([
                $this->phpBinary(),
                base_path('tests/Feature/CentralWallet/Support/mysql_p01_60_reservation_worker.php'),
                '--payload='.$payloadPath,
                '--ready='.$readyPath,
                '--go='.$go,
                '--result='.$resultPath,
            ], 180);
            $process->start();
            $processes[] = ['process' => $process, 'ready' => $readyPath];
        }

        $deadline = microtime(true) + 120;
        foreach ($processes as $item) {
            while (! is_file($item['ready'])) {
                if (microtime(true) > $deadline) {
                    foreach ($processes as $running) {
                        $running['process']->stop(0.2);
                    }
                    $this->fail('Workers did not reach the InnoDB start barrier.');
                }
                usleep(2000);
            }
        }

        file_put_contents($go, '1');

        foreach ($processes as $item) {
            $item['process']->wait();
            $this->assertTrue($item['process']->isSuccessful(), $item['process']->getErrorOutput());
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
     * @param  list<array<string, mixed>>  $results
     */
    private function assertCompetingReserveOutcomes(array $context, array $results): void
    {
        $this->assertCount(2, $results);
        $this->assertIndependentConnections($results);

        $created = array_values(array_filter($results, fn (array $row): bool => ($row['created'] ?? false) === true));
        $rejected = array_values(array_filter($results, fn (array $row): bool => ($row['validation'] ?? false) === true));

        $this->assertCount(1, $created, json_encode($results));
        $this->assertCount(1, $rejected, json_encode($results));

        $pdo = $this->safePdo();
        $cwid = (string) $context['central_wallet_id'];
        $active = (int) $pdo->query(
            'select count(*) from central_wallet_reservations where central_wallet_id = '
            .$pdo->quote($cwid)." and state = 'active'"
        )->fetchColumn();
        $this->assertSame(1, $active, json_encode($results));

        $debits = (int) $pdo->query(
            'select count(*) from central_wallet_ledger_entries where central_wallet_id = '
            .$pdo->quote($cwid)." and entry_type = 'debit'"
        )->fetchColumn();
        $this->assertSame(0, $debits, json_encode($results));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $results
     */
    private function assertConcurrentCommitOutcomes(array $context, array $results): void
    {
        $this->assertCount(2, $results);
        $this->assertIndependentConnections($results);

        foreach ($results as $row) {
            $this->assertTrue(($row['ok'] ?? false) === true, json_encode($row));
        }

        $pdo = $this->safePdo();
        $cwid = (string) $context['central_wallet_id'];
        $debits = (int) $pdo->query(
            'select count(*) from central_wallet_ledger_entries where central_wallet_id = '
            .$pdo->quote($cwid)." and entry_type = 'debit'"
        )->fetchColumn();
        $this->assertSame(1, $debits, json_encode($results));

        $reservationId = (string) ($context['reservation_id'] ?? '');
        $state = (string) $pdo->query(
            'select state from central_wallet_reservations where id = '.$pdo->quote($reservationId)
        )->fetchColumn();
        $this->assertSame('committed', $state, json_encode($results));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertIndependentConnections(array $results): void
    {
        $this->assertNotSame($results[0]['connection_id'] ?? null, $results[1]['connection_id'] ?? null);
        $this->assertNotEmpty($results[0]['connection_id'] ?? null);
        $this->assertNotEmpty($results[1]['connection_id'] ?? null);
    }

    private function assertSafeMysqlTarget(): void
    {
        $host = getenv('P01_60_RESERVATION_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('P01_60_RESERVATION_MYSQL_DATABASE') ?: self::SAFE_DATABASE;

        $this->assertTrue(P01_60ReservationMysqlGate::isAllowedHost($host));
        $this->assertTrue(P01_60ReservationMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(P01_60ReservationMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('P01_60_RESERVATION_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('P01_60_RESERVATION_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT;
        $database = getenv('P01_60_RESERVATION_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('P01_60_RESERVATION_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER;
        $password = getenv('P01_60_RESERVATION_MYSQL_PASSWORD') !== false
            ? (string) getenv('P01_60_RESERVATION_MYSQL_PASSWORD')
            : self::DEFAULT_MYSQL_PASSWORD;

        if ($port === '' || $port === '3306') {
            $this->markTestSkipped(
                'MariaDB test environment unavailable → BLOCKED. Need disposable '.self::SAFE_DATABASE
                .' on loopback non-3306 port.'
            );
        }

        try {
            return new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped('Disposable MariaDB is not reachable on '.$host.':'.$port.': '.$exception->getMessage());
        }
    }

    private function safePdo(): PDO
    {
        $pdo = $this->probeSafeMysqlOrSkip();
        $database = (string) $pdo->query('select database()')->fetchColumn();
        $this->assertTrue(P01_60ReservationMysqlGate::isAllowedDatabase($database));

        return $pdo;
    }

    private function process(array $command, int $timeout): Process
    {
        $process = new Process($command, base_path(), null, null, $timeout);
        $process->setEnv([
            'P01_60_RESERVATION_MYSQL_HOST' => getenv('P01_60_RESERVATION_MYSQL_HOST') ?: '127.0.0.1',
            'P01_60_RESERVATION_MYSQL_PORT' => getenv('P01_60_RESERVATION_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT,
            'P01_60_RESERVATION_MYSQL_DATABASE' => getenv('P01_60_RESERVATION_MYSQL_DATABASE') ?: self::SAFE_DATABASE,
            'P01_60_RESERVATION_MYSQL_USERNAME' => getenv('P01_60_RESERVATION_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER,
            'P01_60_RESERVATION_MYSQL_PASSWORD' => getenv('P01_60_RESERVATION_MYSQL_PASSWORD') !== false
                ? (string) getenv('P01_60_RESERVATION_MYSQL_PASSWORD')
                : self::DEFAULT_MYSQL_PASSWORD,
            'APP_ENV' => getenv('APP_ENV') ?: 'testing',
            'PHP_INI_SCAN_DIR' => '',
        ]);

        return $process;
    }
}
