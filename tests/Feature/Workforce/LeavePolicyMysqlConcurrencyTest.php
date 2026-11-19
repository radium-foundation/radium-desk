<?php

namespace Tests\Feature\Workforce;

use App\Enums\LeaveRequestStatus;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\Workforce\Support\LeavePolicyMysqlGate;
use Tests\TestCase;

#[Group('permanent_regression')]
class LeavePolicyMysqlConcurrencyTest extends TestCase
{
    private const DEFAULT_PORT = '33118';

    private const DEFAULT_USER = 'rd_leave_policy_test';

    private const DEFAULT_PASSWORD = 'rd_leave_policy_test_pw';

    private function phpBinary(): string
    {
        $candidate = '/opt/homebrew/bin/php';

        return is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    public function test_gate_rejects_production_hosts_and_database(): void
    {
        $this->assertFalse(LeavePolicyMysqlGate::isAllowedHost('187.127.129.16'));
        $this->assertTrue(LeavePolicyMysqlGate::isAllowedDatabase(LeavePolicyMysqlGate::DATABASE));
        $this->assertFalse(LeavePolicyMysqlGate::isAllowedDatabase('radium_desk'));
    }

    public function test_concurrent_admin_pair_approvals_allow_only_one(): void
    {
        $this->runScenario('admin_pair', repetitions: 2);
    }

    public function test_concurrent_triad_approvals_allow_only_one_third_member(): void
    {
        $this->runScenario('triad_race', repetitions: 2);
    }

    public function test_duplicate_concurrent_approve_is_idempotent_by_status(): void
    {
        $this->runScenario('duplicate_approve', repetitions: 2);
    }

    public function test_concurrent_monthly_cap_approve_allows_only_one_without_override(): void
    {
        $this->runScenario('monthly_cap_race', repetitions: 2);
    }

    private function runScenario(string $scenario, int $repetitions): void
    {
        for ($run = 1; $run <= $repetitions; $run++) {
            $context = $this->prepareContextOrSkip($scenario, 'r'.$run);
            $results = $this->runWorkers($context);

            $successes = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
            $failures = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));

            $this->assertCount(2, $results);
            $this->assertSame(1, count($successes), json_encode($results));
            $this->assertSame(1, count($failures), json_encode($results));
            $this->assertNotSame($results[0]['connection_id'] ?? null, $results[1]['connection_id'] ?? null);

            foreach ($failures as $failure) {
                $this->assertTrue(
                    ($failure['validation'] ?? false) === true,
                    json_encode($failure),
                );
            }

            if ($scenario === 'duplicate_approve') {
                $leaveId = (int) $context['worker0']['leave_id'];
                $approvedCount = $this->safePdo()->query(
                    "select count(*) from leave_requests where id = {$leaveId} and status = 'approved'",
                )->fetchColumn();
                $this->assertSame(1, (int) $approvedCount);
            }

            if ($scenario === 'monthly_cap_race') {
                $approvedManual = $this->safePdo()->query(
                    "select count(*) from leave_requests where is_manual_exception = 1 and status = 'approved'",
                )->fetchColumn();
                $this->assertSame(2, (int) $approvedManual);
            }

            if (in_array($scenario, ['admin_pair', 'triad_race'], true)) {
                $day = $context['day'];
                $onDay = $this->safePdo()->query(
                    "select count(*) from leave_requests where status = '".LeaveRequestStatus::Approved->value."' and start_date = ".$this->safePdo()->quote($day),
                )->fetchColumn();
                $this->assertLessThanOrEqual(2, (int) $onDay);
            }
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
            base_path('tests/Feature/Workforce/Support/mysql_leave_policy_prepare.php'),
            '--scenario='.$scenario,
            '--suffix='.$suffix,
        ], 600);
        $prepare->mustRun();

        $output = trim($prepare->getOutput());
        $jsonLine = $output;
        if ($output !== '' && ! str_starts_with($output, '{')) {
            $lines = preg_split("/\r\n|\n|\r/", $output) ?: [];
            $candidates = array_values(array_filter($lines, fn (string $line): bool => str_starts_with(ltrim($line), '{')));
            $jsonLine = $candidates === [] ? $output : (string) end($candidates);
        }

        $context = json_decode($jsonLine, true);
        $this->assertIsArray($context, 'Prepare JSON: '.$output);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function runWorkers(array $context): array
    {
        $dir = sys_get_temp_dir().'/leave-policy-mysql-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        $go = $dir.'/go';
        $processes = [];
        $resultPaths = [];

        foreach (['worker0', 'worker1'] as $workerLabel) {
            $payloadPath = $dir.'/payload-'.$workerLabel.'.json';
            $readyPath = $dir.'/ready-'.$workerLabel;
            $resultPath = $dir.'/result-'.$workerLabel.'.json';
            $resultPaths[] = $resultPath;

            $payload = array_merge($context, ['worker_label' => $workerLabel]);
            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = $this->process([
                $this->phpBinary(),
                base_path('tests/Feature/Workforce/Support/mysql_leave_policy_worker.php'),
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

    private function assertSafeMysqlTarget(): void
    {
        $host = getenv('LEAVE_POLICY_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('LEAVE_POLICY_MYSQL_DATABASE') ?: LeavePolicyMysqlGate::DATABASE;

        $this->assertTrue(LeavePolicyMysqlGate::isAllowedHost($host));
        $this->assertTrue(LeavePolicyMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(LeavePolicyMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('LEAVE_POLICY_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('LEAVE_POLICY_MYSQL_PORT') ?: self::DEFAULT_PORT;
        $database = getenv('LEAVE_POLICY_MYSQL_DATABASE') ?: LeavePolicyMysqlGate::DATABASE;
        $username = getenv('LEAVE_POLICY_MYSQL_USERNAME') ?: self::DEFAULT_USER;
        $password = getenv('LEAVE_POLICY_MYSQL_PASSWORD') !== false
            ? (string) getenv('LEAVE_POLICY_MYSQL_PASSWORD')
            : self::DEFAULT_PASSWORD;

        if ($port === '' || $port === '3306') {
            $this->markTestSkipped('Disposable MariaDB required on loopback port other than 3306.');
        }

        try {
            return new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped('MariaDB unavailable: '.$exception->getMessage());
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

        $env['LEAVE_POLICY_MYSQL_HOST'] = (string) (getenv('LEAVE_POLICY_MYSQL_HOST') ?: '127.0.0.1');
        $env['LEAVE_POLICY_MYSQL_PORT'] = (string) (getenv('LEAVE_POLICY_MYSQL_PORT') ?: self::DEFAULT_PORT);
        $env['LEAVE_POLICY_MYSQL_DATABASE'] = LeavePolicyMysqlGate::DATABASE;
        $env['LEAVE_POLICY_MYSQL_USERNAME'] = (string) (getenv('LEAVE_POLICY_MYSQL_USERNAME') ?: self::DEFAULT_USER);
        $env['LEAVE_POLICY_MYSQL_PASSWORD'] = getenv('LEAVE_POLICY_MYSQL_PASSWORD') !== false
            ? (string) getenv('LEAVE_POLICY_MYSQL_PASSWORD')
            : self::DEFAULT_PASSWORD;
        $env['APP_ENV'] = 'testing';

        return new Process($command, base_path(), $env, null, $timeout);
    }
}
