<?php

namespace Tests\Feature\StatutoryInvoice;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\StatutoryInvoice\Support\StatutoryCommerceMintMysqlGate;
use Tests\TestCase;

#[Group('permanent_regression')]
class StatutoryCommerceMintMysqlConcurrencyTest extends TestCase
{
    public const SAFE_DATABASE = StatutoryCommerceMintMysqlGate::DATABASE;

    private const DEFAULT_MYSQL_PORT = '33118';

    private const DEFAULT_MYSQL_USER = 'rd_statutory_commerce_mint_test';

    private const DEFAULT_MYSQL_PASSWORD = 'rd_statutory_commerce_mint_test_pw';

    private function phpBinary(): string
    {
        $candidate = '/opt/homebrew/bin/php';

        return is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    public function test_gate_allows_only_loopback_hosts_and_named_database(): void
    {
        $this->assertTrue(StatutoryCommerceMintMysqlGate::isAllowedHost('127.0.0.1'));
        $this->assertFalse(StatutoryCommerceMintMysqlGate::isAllowedHost('187.127.129.16'));
        $this->assertTrue(StatutoryCommerceMintMysqlGate::isAllowedDatabase(self::SAFE_DATABASE));
        $this->assertFalse(StatutoryCommerceMintMysqlGate::isAllowedDatabase('radium_desk'));
    }

    public function test_two_workers_concurrent_mint_same_order_single_invoice(): void
    {
        for ($run = 1; $run <= 2; $run++) {
            $context = $this->prepareContextOrSkip([
                'suffix' => 'mint'.$run,
                'scenario' => 'single_order',
            ]);

            $results = $this->runWorkers($context, 2, 'mint');
            $this->assertConcurrentMintSameOrder($results);
        }
    }

    public function test_two_workers_concurrent_mint_distinct_orders_unique_invoices(): void
    {
        $context = $this->prepareContextOrSkip([
            'suffix' => 'dual'.bin2hex(random_bytes(2)),
            'scenario' => 'dual_order',
        ]);

        $results = $this->runDualOrderMintWorkers($context);
        $this->assertDistinctOrderMints($results);
    }

    public function test_three_workers_concurrent_enqueue_single_publication(): void
    {
        for ($run = 1; $run <= 2; $run++) {
            $context = $this->prepareContextOrSkip([
                'suffix' => 'pub'.$run,
                'scenario' => 'preissued_invoice',
            ]);

            $results = $this->runWorkers($context, 3, 'enqueue');
            $this->assertConcurrentEnqueueIdempotent($results);
        }
    }

    public function test_worker_sequential_duplicate_mint_after_barrier(): void
    {
        $context = $this->prepareContextOrSkip([
            'suffix' => 'retry'.bin2hex(random_bytes(2)),
            'scenario' => 'single_order',
        ]);

        $results = $this->runWorkers($context, 1, 'mint_twice');
        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(1, $result['statutory_invoice_count'] ?? null);
        $this->assertSame(1, $result['allocation_count'] ?? null);
        $this->assertArrayHasKey('buyer_gstin', $result);
        $this->assertBlankBuyerGstin($result['buyer_gstin']);
        $this->assertSame($result['invoice_id'], $result['conversion_statutory_invoice_id'] ?? null);
    }

    private function assertBlankBuyerGstin(mixed $value): void
    {
        $this->assertTrue($value === null || $value === '', 'Expected blank buyer GSTIN, got: '.json_encode($value));
    }

    /**
     * @param  array{suffix: string, scenario?: string}  $options
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(array $options): array
    {
        $this->assertSafeMysqlTarget();
        $this->probeSafeMysqlOrSkip();

        $prepare = $this->process(
            [
                $this->phpBinary(),
                base_path('tests/Feature/StatutoryInvoice/Support/mysql_statutory_commerce_mint_prepare.php'),
                '--suffix='.$options['suffix'],
                '--scenario='.($options['scenario'] ?? 'single_order'),
            ],
            900,
        );
        $prepare->mustRun();

        return $this->decodeJsonLine($prepare->getOutput());
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonLine(string $output): array
    {
        $output = trim($output);
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
    private function runWorkers(array $context, int $workerCount, string $operation): array
    {
        $dir = sys_get_temp_dir().'/stat-mint-conc-'.bin2hex(random_bytes(6));
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
                'operation' => $operation,
            ]);

            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = $this->process([
                $this->phpBinary(),
                base_path('tests/Feature/StatutoryInvoice/Support/mysql_statutory_commerce_mint_worker.php'),
                '--payload='.$payloadPath,
                '--ready='.$readyPath,
                '--go='.$go,
                '--result='.$resultPath,
            ], 600);
            $process->start();
            $processes[] = ['process' => $process, 'ready' => $readyPath];
        }

        $this->waitForBarrierAndGo($processes, $go);

        return $this->waitForWorkerResults($processes, $resultPaths);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function runDualOrderMintWorkers(array $context): array
    {
        $orders = $context['commerce_orders'] ?? [];
        $this->assertCount(2, $orders);

        $dir = sys_get_temp_dir().'/stat-mint-dual-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $go = $dir.'/go';
        $processes = [];
        $resultPaths = [];

        foreach ($orders as $index => $orderMeta) {
            $payloadPath = $dir.'/payload-'.$index.'.json';
            $readyPath = $dir.'/ready-'.$index;
            $resultPath = $dir.'/result-'.$index.'.json';
            $resultPaths[] = $resultPath;

            $payload = array_merge($context, [
                'worker_label' => 'w'.$index,
                'operation' => 'mint',
                'commerce_order_id' => $orderMeta['commerce_order_id'],
            ]);

            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = $this->process([
                $this->phpBinary(),
                base_path('tests/Feature/StatutoryInvoice/Support/mysql_statutory_commerce_mint_worker.php'),
                '--payload='.$payloadPath,
                '--ready='.$readyPath,
                '--go='.$go,
                '--result='.$resultPath,
            ], 600);
            $process->start();
            $processes[] = ['process' => $process, 'ready' => $readyPath];
        }

        $this->waitForBarrierAndGo($processes, $go);

        return $this->waitForWorkerResults($processes, $resultPaths);
    }

    /**
     * @param  list<array{process: Process, ready: string}>  $processes
     * @param  list<string>  $resultPaths
     * @return list<array<string, mixed>>
     */
    private function waitForWorkerResults(array $processes, array $resultPaths): array
    {
        foreach ($processes as $index => $item) {
            $item['process']->wait();
            $raw = is_file($resultPaths[$index]) ? (string) file_get_contents($resultPaths[$index]) : '';
            if (! $item['process']->isSuccessful()) {
                $this->fail(
                    'Worker process exit '.$item['process']->getExitCode().': '
                    .$item['process']->getErrorOutput().$item['process']->getOutput().' Result: '.$raw,
                );
            }
        }

        return $this->readResults($resultPaths);
    }

    /**
     * @param  list<array{process: Process, ready: string}>  $processes
     */
    private function waitForBarrierAndGo(array $processes, string $goPath): void
    {
        $deadline = microtime(true) + 180;
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

        touch($goPath);
    }

    /**
     * @param  list<string>  $resultPaths
     * @return list<array<string, mixed>>
     */
    private function readResults(array $resultPaths): array
    {
        $results = [];
        foreach ($resultPaths as $path) {
            $raw = (string) file_get_contents($path);
            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded);
            $this->assertTrue($decoded['ok'] ?? false, 'Worker result: '.$raw);
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertConcurrentMintSameOrder(array $results): void
    {
        $this->assertCount(2, $results);
        $this->assertIndependentConnections($results);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($result));
            $this->assertSame(1, $result['statutory_invoice_count'] ?? null);
            $this->assertSame(1, $result['allocation_count'] ?? null);
            $this->assertArrayHasKey('buyer_gstin', $result);
            $this->assertBlankBuyerGstin($result['buyer_gstin']);
            $this->assertSame($result['invoice_id'], $result['conversion_statutory_invoice_id'] ?? null);
            $this->assertSame(1, $result['publication_count'] ?? null);
            $this->assertSame(1, $result['outbox_receipt_count'] ?? null);
        }

        $this->assertSame($results[0]['invoice_id'] ?? null, $results[1]['invoice_id'] ?? null);
        $this->assertSame($results[0]['invoice_number'] ?? null, $results[1]['invoice_number'] ?? null);
        $this->assertNotSame($results[0]['invoice_number'] ?? '', '');
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertDistinctOrderMints(array $results): void
    {
        $this->assertCount(2, $results);
        $this->assertIndependentConnections($results);

        $invoiceIds = [];
        $numbers = [];
        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($result));
            $this->assertSame(2, $result['statutory_invoice_count'] ?? null);
            $this->assertSame(2, $result['allocation_count'] ?? null);
            $invoiceIds[] = $result['invoice_id'] ?? null;
            $numbers[] = $result['invoice_number'] ?? null;
        }

        $this->assertNotSame($invoiceIds[0], $invoiceIds[1]);
        $this->assertNotSame($numbers[0], $numbers[1]);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertConcurrentEnqueueIdempotent(array $results): void
    {
        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($result));
            $this->assertSame(1, $result['statutory_invoice_count'] ?? null);
            $this->assertSame(1, $result['publication_count'] ?? null);
            $this->assertSame(1, $result['outbox_receipt_count'] ?? null);
        }

        $this->assertSame($results[0]['publication_count'] ?? null, $results[1]['publication_count'] ?? null);
        $this->assertSame($results[0]['outbox_receipt_count'] ?? null, $results[2]['outbox_receipt_count'] ?? null);
        $this->assertSame(1, $results[0]['statutory_invoice_count'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertIndependentConnections(array $results): void
    {
        if (count($results) < 2) {
            return;
        }

        $this->assertNotSame($results[0]['connection_id'] ?? null, $results[1]['connection_id'] ?? null);
        $this->assertNotEmpty($results[0]['connection_id'] ?? null);
        $this->assertNotEmpty($results[1]['connection_id'] ?? null);
    }

    private function assertSafeMysqlTarget(): void
    {
        $host = getenv('STATUTORY_COMMERCE_MINT_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('STATUTORY_COMMERCE_MINT_MYSQL_DATABASE') ?: self::SAFE_DATABASE;

        $this->assertTrue(StatutoryCommerceMintMysqlGate::isAllowedHost($host));
        $this->assertTrue(StatutoryCommerceMintMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(StatutoryCommerceMintMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('STATUTORY_COMMERCE_MINT_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('STATUTORY_COMMERCE_MINT_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT;
        $database = getenv('STATUTORY_COMMERCE_MINT_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('STATUTORY_COMMERCE_MINT_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER;
        $password = getenv('STATUTORY_COMMERCE_MINT_MYSQL_PASSWORD') !== false
            ? (string) getenv('STATUTORY_COMMERCE_MINT_MYSQL_PASSWORD')
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

    /**
     * @param  list<string>  $command
     */
    private function process(array $command, int $timeout): Process
    {
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }

        $host = (string) (getenv('STATUTORY_COMMERCE_MINT_MYSQL_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('STATUTORY_COMMERCE_MINT_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT);
        $username = (string) (getenv('STATUTORY_COMMERCE_MINT_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER);
        $password = getenv('STATUTORY_COMMERCE_MINT_MYSQL_PASSWORD') !== false
            ? (string) getenv('STATUTORY_COMMERCE_MINT_MYSQL_PASSWORD')
            : self::DEFAULT_MYSQL_PASSWORD;

        $env['STATUTORY_COMMERCE_MINT_MYSQL_HOST'] = $host;
        $env['STATUTORY_COMMERCE_MINT_MYSQL_PORT'] = $port;
        $env['STATUTORY_COMMERCE_MINT_MYSQL_DATABASE'] = self::SAFE_DATABASE;
        $env['STATUTORY_COMMERCE_MINT_MYSQL_USERNAME'] = $username;
        $env['STATUTORY_COMMERCE_MINT_MYSQL_PASSWORD'] = $password;
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
