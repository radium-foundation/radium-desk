<?php

namespace Tests\Feature\Refunds;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\Refunds\Support\WalletAutoApprovalConcurrencyMysqlAssertions;
use Tests\Feature\Refunds\Support\WalletAutoApprovalMysqlGate;
use Tests\TestCase;

#[Group('permanent_regression')]
class RefundWalletAutoApprovalMysqlConcurrencyTest extends TestCase
{
    private const DEFAULT_PORT = '33118';

    private const DEFAULT_USER = 'rd_wallet_auto_approval_test';

    private const DEFAULT_PASSWORD = 'rd_wallet_auto_approval_test_pw';

    protected function setUp(): void
    {
        parent::setUp();

        WalletAutoApprovalMysqlGate::forceProcessEnv([
            'WALLET_AUTO_APPROVAL_MYSQL_HOST' => getenv('WALLET_AUTO_APPROVAL_MYSQL_HOST') ?: '127.0.0.1',
            'WALLET_AUTO_APPROVAL_MYSQL_PORT' => getenv('WALLET_AUTO_APPROVAL_MYSQL_PORT') ?: self::DEFAULT_PORT,
            'WALLET_AUTO_APPROVAL_MYSQL_DATABASE' => getenv('WALLET_AUTO_APPROVAL_MYSQL_DATABASE') ?: WalletAutoApprovalMysqlGate::DATABASE,
            'WALLET_AUTO_APPROVAL_MYSQL_USERNAME' => getenv('WALLET_AUTO_APPROVAL_MYSQL_USERNAME') ?: self::DEFAULT_USER,
            'WALLET_AUTO_APPROVAL_MYSQL_PASSWORD' => getenv('WALLET_AUTO_APPROVAL_MYSQL_PASSWORD') !== false
                ? (string) getenv('WALLET_AUTO_APPROVAL_MYSQL_PASSWORD')
                : self::DEFAULT_PASSWORD,
            'APP_ENV' => 'testing',
        ]);
    }

    private function phpBinary(): string
    {
        $candidate = '/opt/homebrew/bin/php';

        return is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    public function test_gate_rejects_production_targets(): void
    {
        $this->assertFalse(WalletAutoApprovalMysqlGate::isAllowedHost('187.127.129.16'));
        $this->assertTrue(WalletAutoApprovalMysqlGate::isAllowedDatabase(WalletAutoApprovalMysqlGate::DATABASE));
        $this->assertFalse(WalletAutoApprovalMysqlGate::isAllowedDatabase('radium_desk'));
    }

    public function test_concurrent_dual_create_on_same_order_allows_at_most_one_auto_approval(): void
    {
        $context = $this->prepareContextOrSkip('dual_create', 'dc1');
        $results = $this->runWorkers($context);

        $this->assertCount(2, $results);
        $this->assertIndependentConnections($results);

        $successes = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $failures = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));

        $this->assertGreaterThanOrEqual(1, count($successes), json_encode($results));
        $this->assertLessThanOrEqual(2, count($successes), json_encode($results));

        $autoApproved = array_values(array_filter(
            $successes,
            fn (array $row): bool => ($row['refund_status'] ?? '') === 'pending_execution',
        ));
        $this->assertLessThanOrEqual(1, count($autoApproved), json_encode($results));

        $pdo = $this->safePdo();
        $orderId = (int) $context['order_id'];
        $refundRows = (int) $pdo->query('select count(*) from refund_requests where order_id = '.$orderId)->fetchColumn();
        $this->assertLessThanOrEqual(2, $refundRows);
        $this->assertGreaterThanOrEqual(1, $refundRows);

        $autoAudits = (int) $pdo->query(
            "select count(*) from audit_logs where event = 'refund.approved' and JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.approval_source')) = 'auto'",
        )->fetchColumn();
        $this->assertLessThanOrEqual(1, $autoAudits);

        foreach ($failures as $failure) {
            $this->assertTrue(
                ($failure['validation'] ?? false) === true,
                json_encode($failure),
            );
        }
    }

    public function test_concurrent_auto_create_vs_manual_approve_on_same_order_serializes_to_single_winner(): void
    {
        foreach (['ac1', 'ac2', 'ac3'] as $suffix) {
            $context = $this->prepareContextOrSkip('auto_create_vs_manual_approve', $suffix);
            $results = $this->runWorkers($context);

            $this->assertIndependentConnections($results);
            $winner = WalletAutoApprovalConcurrencyMysqlAssertions::assertExactlyOneApprovalSuccess($results);
            $this->assertSame('pending_execution', $winner['refund_status'] ?? null);

            $pdo = $this->safePdo();
            $refundId = (int) $context['refund_id'];
            $orderId = (int) $context['order_id'];
            WalletAutoApprovalConcurrencyMysqlAssertions::assertSingleApprovalWinner($pdo, $refundId, $orderId);

            $refundRows = (int) $pdo->query('select count(*) from refund_requests where order_id = '.$orderId)->fetchColumn();
            $this->assertLessThanOrEqual(2, $refundRows);
        }
    }

    public function test_concurrent_auto_promotion_vs_manual_approve_serializes_to_single_winner(): void
    {
        foreach (['ap1', 'ap2', 'ap3'] as $suffix) {
            $context = $this->prepareContextOrSkip('auto_promote_vs_manual_approve', $suffix);
            $results = $this->runWorkers($context);

            $this->assertIndependentConnections($results);
            $winner = WalletAutoApprovalConcurrencyMysqlAssertions::assertExactlyOneApprovalSuccess($results);
            $this->assertSame('pending_execution', $winner['refund_status'] ?? null);
            $this->assertSame('wallet', $winner['approved_method'] ?? null);

            $pdo = $this->safePdo();
            $refundId = (int) $context['refund_id'];
            $orderId = (int) $context['order_id'];
            WalletAutoApprovalConcurrencyMysqlAssertions::assertSingleApprovalWinner($pdo, $refundId, $orderId);

            $autoAudit = (int) $pdo->query(
                "select count(*) from audit_logs where event = 'refund.approved' and auditable_id = {$refundId} "
                ."and JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.approval_source')) = 'auto'",
            )->fetchColumn();
            $manualAudit = (int) $pdo->query(
                "select count(*) from audit_logs where event = 'refund.approved' and auditable_id = {$refundId} "
                ."and (JSON_EXTRACT(new_values, '$.approval_source') is null or JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.approval_source')) != 'auto')",
            )->fetchColumn();
            $this->assertSame(1, $autoAudit + $manualAudit);
            $this->assertTrue($autoAudit === 1 xor $manualAudit === 1, 'Exactly one auto or manual approval source expected.');
        }
    }

    public function test_retry_manual_and_auto_promotion_after_approval_is_idempotent(): void
    {
        $context = $this->prepareContextOrSkip('auto_promote_vs_manual_approve', 'id1');
        $results = $this->runWorkers($context);
        WalletAutoApprovalConcurrencyMysqlAssertions::assertExactlyOneApprovalSuccess($results);

        $retryApprove = $this->runSingleWorker($context, 'worker1', ['action' => 'approve']);
        $this->assertFalse($retryApprove['ok'] ?? true);
        $this->assertTrue($retryApprove['validation'] ?? false);

        $retryAuto = $this->runSingleWorker($context, 'worker0', ['action' => 'auto_promote']);
        $this->assertFalse($retryAuto['ok'] ?? true);
        $this->assertTrue($retryAuto['validation'] ?? false);

        $pdo = $this->safePdo();
        WalletAutoApprovalConcurrencyMysqlAssertions::assertSingleApprovalWinner(
            $pdo,
            (int) $context['refund_id'],
            (int) $context['order_id'],
        );
    }

    public function test_concurrent_manual_approve_race_serializes_to_one_pending_execution(): void
    {
        $context = $this->prepareContextOrSkip('manual_approve_race', 'mr1');
        $results = $this->runWorkers($context);

        $successes = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $failures = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));

        $this->assertSame(1, count($successes), json_encode($results));
        $this->assertSame(1, count($failures), json_encode($results));
        $this->assertSame('pending_execution', $successes[0]['refund_status'] ?? null);

        $pdo = $this->safePdo();
        $refundId = (int) $context['refund_id'];
        $status = (string) $pdo->query('select status from refund_requests where id = '.$refundId)->fetchColumn();
        $this->assertSame('pending_execution', $status);

        $approvalAudits = (int) $pdo->query(
            "select count(*) from audit_logs where event = 'refund.approved' and auditable_id = {$refundId}",
        )->fetchColumn();
        $this->assertSame(1, $approvalAudits);
    }

    public function test_concurrent_independent_orders_both_auto_approve(): void
    {
        $context = $this->prepareContextOrSkip('independent_dual_create', 'in1');
        $results = $this->runWorkers($context);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($result));
            $this->assertSame('pending_execution', $result['refund_status'] ?? null);
            $this->assertSame('wallet', $result['approved_method'] ?? null);
        }

        $this->assertNotSame($results[0]['connection_id'] ?? null, $results[1]['connection_id'] ?? null);
    }

    public function test_rollback_probe_leaves_no_refund_or_auto_audit_rows(): void
    {
        $context = $this->prepareContextOrSkip('rollback_probe', 'rb1');
        $context['worker0'] = ['action' => 'rollback_probe'];
        unset($context['worker1']);

        $dir = sys_get_temp_dir().'/wallet-auto-rollback-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $payloadPath = $dir.'/payload.json';
        $readyPath = $dir.'/ready';
        $goPath = $dir.'/go';
        $resultPath = $dir.'/result.json';

        file_put_contents($payloadPath, json_encode(array_merge($context, ['worker_label' => 'worker0']), JSON_THROW_ON_ERROR));

        $process = $this->process([
            $this->phpBinary(),
            base_path('tests/Feature/Refunds/Support/mysql_wallet_auto_approval_worker.php'),
            '--payload='.$payloadPath,
            '--ready='.$readyPath,
            '--go='.$goPath,
            '--result='.$resultPath,
        ], 120);
        $process->start();

        $deadline = microtime(true) + 60;
        while (! is_file($readyPath)) {
            if (microtime(true) > $deadline) {
                $process->stop(0.2);
                $this->fail('Rollback worker did not signal ready.');
            }
            usleep(2000);
        }

        file_put_contents($goPath, '1');
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $result = json_decode((string) file_get_contents($resultPath), true);
        $this->assertIsArray($result);
        $this->assertTrue($result['ok'] ?? false, json_encode($result));
        $this->assertSame(0, $result['refund_rows'] ?? -1);
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
            base_path('tests/Feature/Refunds/Support/mysql_wallet_auto_approval_prepare.php'),
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
        $dir = sys_get_temp_dir().'/wallet-auto-approval-mysql-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        $go = $dir.'/go';
        $processes = [];
        $resultPaths = [];

        foreach (['worker0', 'worker1'] as $workerLabel) {
            if (! isset($context[$workerLabel]) && $workerLabel === 'worker1') {
                continue;
            }

            $payloadPath = $dir.'/payload-'.$workerLabel.'.json';
            $readyPath = $dir.'/ready-'.$workerLabel;
            $resultPath = $dir.'/result-'.$workerLabel.'.json';
            $resultPaths[] = $resultPath;

            file_put_contents(
                $payloadPath,
                json_encode(array_merge($context, ['worker_label' => $workerLabel]), JSON_THROW_ON_ERROR),
            );

            $process = $this->process([
                $this->phpBinary(),
                base_path('tests/Feature/Refunds/Support/mysql_wallet_auto_approval_worker.php'),
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
     * @param  array<string, mixed>  $workerOverrides
     * @return array<string, mixed>
     */
    private function runSingleWorker(array $context, string $workerLabel, array $workerOverrides): array
    {
        $dir = sys_get_temp_dir().'/wallet-auto-approval-single-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        $payloadPath = $dir.'/payload.json';
        $readyPath = $dir.'/ready';
        $goPath = $dir.'/go';
        $resultPath = $dir.'/result.json';

        $merged = array_merge($context, [$workerLabel => $workerOverrides, 'worker_label' => $workerLabel]);
        file_put_contents($payloadPath, json_encode($merged, JSON_THROW_ON_ERROR));

        $process = $this->process([
            $this->phpBinary(),
            '-n',
            base_path('tests/Feature/Refunds/Support/mysql_wallet_auto_approval_worker.php'),
            '--payload='.$payloadPath,
            '--ready='.$readyPath,
            '--go='.$goPath,
            '--result='.$resultPath,
        ], 120);
        $process->start();

        $deadline = microtime(true) + 60;
        while (! is_file($readyPath)) {
            if (microtime(true) > $deadline) {
                $process->stop(0.2);
                $this->fail('Single worker did not signal ready.');
            }
            usleep(2000);
        }

        file_put_contents($goPath, '1');
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $decoded = json_decode((string) file_get_contents($resultPath), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertIndependentConnections(array $results): void
    {
        $this->assertNotSame($results[0]['connection_id'] ?? null, $results[1]['connection_id'] ?? null);
    }

    private function assertSafeMysqlTarget(): void
    {
        $host = getenv('WALLET_AUTO_APPROVAL_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('WALLET_AUTO_APPROVAL_MYSQL_DATABASE') ?: WalletAutoApprovalMysqlGate::DATABASE;

        $this->assertTrue(WalletAutoApprovalMysqlGate::isAllowedHost($host));
        $this->assertTrue(WalletAutoApprovalMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(WalletAutoApprovalMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('WALLET_AUTO_APPROVAL_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('WALLET_AUTO_APPROVAL_MYSQL_PORT') ?: self::DEFAULT_PORT;
        $database = getenv('WALLET_AUTO_APPROVAL_MYSQL_DATABASE') ?: WalletAutoApprovalMysqlGate::DATABASE;
        $username = getenv('WALLET_AUTO_APPROVAL_MYSQL_USERNAME') ?: self::DEFAULT_USER;
        $password = getenv('WALLET_AUTO_APPROVAL_MYSQL_PASSWORD') !== false
            ? (string) getenv('WALLET_AUTO_APPROVAL_MYSQL_PASSWORD')
            : self::DEFAULT_PASSWORD;

        if ($port === '' || $port === '3306') {
            $this->markTestSkipped(
                'MariaDB test environment unavailable → BLOCKED. Need disposable '
                .WalletAutoApprovalMysqlGate::DATABASE.' on loopback non-3306 port.'
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
            $this->markTestSkipped('MariaDB probe failed: '.$exception->getMessage());
        }
    }

    private function safePdo(): PDO
    {
        $pdo = $this->probeSafeMysqlOrSkip();

        return $pdo;
    }

    /**
     * @param  list<string>  $command
     */
    private function process(array $command, int $timeout): Process
    {
        $env = array_merge($_ENV, [
            'WALLET_AUTO_APPROVAL_MYSQL_HOST' => getenv('WALLET_AUTO_APPROVAL_MYSQL_HOST') ?: '127.0.0.1',
            'WALLET_AUTO_APPROVAL_MYSQL_PORT' => getenv('WALLET_AUTO_APPROVAL_MYSQL_PORT') ?: self::DEFAULT_PORT,
            'WALLET_AUTO_APPROVAL_MYSQL_DATABASE' => WalletAutoApprovalMysqlGate::DATABASE,
            'WALLET_AUTO_APPROVAL_MYSQL_USERNAME' => self::DEFAULT_USER,
            'WALLET_AUTO_APPROVAL_MYSQL_PASSWORD' => self::DEFAULT_PASSWORD,
            'APP_ENV' => 'testing',
        ]);

        return new Process($command, base_path(), $env, null, $timeout);
    }
}
