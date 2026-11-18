<?php

namespace Tests\Feature\Refunds;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\Refunds\Support\RefundCompletionMysqlGate;
use Tests\TestCase;

#[Group('permanent_regression')]
class RefundCompletionMysqlConcurrencyTest extends TestCase
{
    public const SAFE_DATABASE = RefundCompletionMysqlGate::DATABASE;

    private const DEFAULT_MYSQL_PORT = '33118';

    private const DEFAULT_MYSQL_USER = 'rd_refund_complete_test';

    private const DEFAULT_MYSQL_PASSWORD = 'rd_refund_complete_test_pw';

    private function phpBinary(): string
    {
        $candidate = '/opt/homebrew/bin/php';

        return is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    public function test_gate_allows_only_loopback_hosts_and_named_database(): void
    {
        $this->assertTrue(RefundCompletionMysqlGate::isAllowedHost('127.0.0.1'));
        $this->assertFalse(RefundCompletionMysqlGate::isAllowedHost('187.127.129.16'));
        $this->assertTrue(RefundCompletionMysqlGate::isAllowedDatabase(self::SAFE_DATABASE));
        $this->assertFalse(RefundCompletionMysqlGate::isAllowedDatabase('radium_desk'));
    }

    public function test_two_workers_concurrent_desk_first_wallet_completion_single_ledger_credit(): void
    {
        $this->runConcurrentWalletCompletionScenario(repetitions: 3);
    }

    public function test_two_workers_concurrent_bank_transfer_completion_single_financial_outcome(): void
    {
        $this->runConcurrentBankCompletionScenario(repetitions: 3);
    }

    private function runConcurrentWalletCompletionScenario(int $repetitions): void
    {
        for ($run = 1; $run <= $repetitions; $run++) {
            $context = $this->prepareContextOrSkip(['method' => 'wallet', 'suffix' => 'w'.$run]);
            $results = $this->runWorkers($context, 2);
            $this->assertConcurrentFinancialOutcomes($context, $results, expectLedger: true);
        }
    }

    private function runConcurrentBankCompletionScenario(int $repetitions): void
    {
        for ($run = 1; $run <= $repetitions; $run++) {
            $context = $this->prepareContextOrSkip(['method' => 'bank', 'suffix' => 'b'.$run]);
            $results = $this->runWorkers($context, 2);
            $this->assertConcurrentFinancialOutcomes($context, $results, expectLedger: false);
        }
    }

    /**
     * @param  array{method: string, suffix: string}  $options
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(array $options): array
    {
        $this->assertSafeMysqlTarget();
        $this->probeSafeMysqlOrSkip();

        $prepare = $this->process(
            [
                $this->phpBinary(),
                base_path('tests/Feature/Refunds/Support/mysql_refund_completion_prepare.php'),
                '--method='.$options['method'],
                '--suffix='.$options['suffix'],
            ],
            600,
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

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function runWorkers(array $context, int $workerCount): array
    {
        $dir = sys_get_temp_dir().'/refund-completion-mysql-'.bin2hex(random_bytes(6));
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
                base_path('tests/Feature/Refunds/Support/mysql_refund_completion_worker.php'),
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
            $this->assertFalse($item['process']->isSuccessful() === false && ($item['process']->getExitCode() ?? 0) > 1, $item['process']->getErrorOutput());
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
    private function assertConcurrentFinancialOutcomes(array $context, array $results, bool $expectLedger): void
    {
        $this->assertCount(2, $results);
        $this->assertIndependentConnections($results);

        $successes = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $failures = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));

        $this->assertGreaterThanOrEqual(1, count($successes), json_encode($results));
        $this->assertLessThanOrEqual(2, count($successes), json_encode($results));
        $this->assertSame(2, count($successes) + count($failures), json_encode($results));

        foreach ($results as $row) {
            if (($row['ok'] ?? false) !== true) {
                $this->assertTrue(
                    ($row['validation'] ?? false) === true || str_contains((string) ($row['message'] ?? ''), 'pending execution'),
                    json_encode($row),
                );
            }
        }

        $pdo = $this->safePdo();
        $refundId = (int) $context['refund_id'];
        $deskReference = (string) $context['desk_reference'];

        $status = (string) $pdo->query("select status from refund_requests where id = {$refundId}")->fetchColumn();
        $this->assertContains($status, ['completed', 'closed'], json_encode($results));

        $completedAudits = (int) $pdo->query(
            "select count(*) from audit_logs where event = 'refund.completed' and auditable_type = 'App\\\\Models\\\\RefundRequest' and auditable_id = {$refundId}"
        )->fetchColumn();
        $this->assertSame(1, $completedAudits, json_encode($results));

        $holdCleared = (int) $pdo->query(
            "select count(*) from audit_logs where event = 'business_hold.cleared' and auditable_id = ".(int) $context['incident_id']
        )->fetchColumn();
        $this->assertLessThanOrEqual(1, $holdCleared);

        if ($expectLedger) {
            $ledgerCredits = (int) $pdo->query(
                'select count(*) from central_wallet_ledger_entries where business_reference = '.$pdo->quote($deskReference)." and entry_type = 'credit'"
            )->fetchColumn();
            $this->assertSame(1, $ledgerCredits, json_encode($results));

            $executionRef = (string) $pdo->query("select execution_reference_no from refund_requests where id = {$refundId}")->fetchColumn();
            $this->assertStringStartsWith('CW:', $executionRef);
        } else {
            $executionRef = (string) $pdo->query("select execution_reference_no from refund_requests where id = {$refundId}")->fetchColumn();
            $this->assertStringStartsWith('UTR-w', $executionRef);
        }
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
        $host = getenv('REFUND_COMPLETION_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('REFUND_COMPLETION_MYSQL_DATABASE') ?: self::SAFE_DATABASE;

        $this->assertTrue(RefundCompletionMysqlGate::isAllowedHost($host));
        $this->assertTrue(RefundCompletionMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(RefundCompletionMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('REFUND_COMPLETION_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('REFUND_COMPLETION_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT;
        $database = getenv('REFUND_COMPLETION_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('REFUND_COMPLETION_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER;
        $password = getenv('REFUND_COMPLETION_MYSQL_PASSWORD') !== false
          ? (string) getenv('REFUND_COMPLETION_MYSQL_PASSWORD')
          : self::DEFAULT_MYSQL_PASSWORD;

        if ($port === '' || $port === '3306') {
            $this->markTestSkipped(
                'MariaDB test environment unavailable → BLOCKER. Need disposable '.self::SAFE_DATABASE
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
            $this->markTestSkipped(
                'MariaDB test environment unavailable → BLOCKER. '.$exception->getMessage()
            );
        }
    }

    private function safePdo(): PDO
    {
        return $this->probeSafeMysqlOrSkip();
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

        $host = (string) (getenv('REFUND_COMPLETION_MYSQL_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('REFUND_COMPLETION_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT);
        $username = (string) (getenv('REFUND_COMPLETION_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER);
        $password = getenv('REFUND_COMPLETION_MYSQL_PASSWORD') !== false
          ? (string) getenv('REFUND_COMPLETION_MYSQL_PASSWORD')
          : self::DEFAULT_MYSQL_PASSWORD;

        $env['REFUND_COMPLETION_MYSQL_HOST'] = $host;
        $env['REFUND_COMPLETION_MYSQL_PORT'] = $port;
        $env['REFUND_COMPLETION_MYSQL_DATABASE'] = self::SAFE_DATABASE;
        $env['REFUND_COMPLETION_MYSQL_USERNAME'] = $username;
        $env['REFUND_COMPLETION_MYSQL_PASSWORD'] = $password;
        $env['DB_CONNECTION'] = 'mysql';
        $env['DB_HOST'] = $host;
        $env['DB_PORT'] = $port;
        $env['DB_DATABASE'] = self::SAFE_DATABASE;
        $env['DB_USERNAME'] = $username;
        $env['DB_PASSWORD'] = $password;
        $env['DB_URL'] = '';
        $env['DB_SOCKET'] = '';
        $env['APP_ENV'] = 'testing';
        $env['CACHE_STORE'] = 'array';
        $env['SESSION_DRIVER'] = 'array';
        $env['QUEUE_CONNECTION'] = 'sync';

        return new Process($command, base_path(), $env, null, $timeout);
    }
}
