<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Services\HardwareFulfilment\HardwareFulfilmentCallbackOutboxWriter;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\HardwareFulfilment\Support\HardwareFulfilmentMysqlGate;
use Tests\TestCase;

class HardwareMixedFulfilmentMysqlAutoInvoiceConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::disconnect('mysql');

        parent::tearDown();
    }

    public const SAFE_DATABASE = HardwareFulfilmentMysqlGate::DATABASE;

    private const DEFAULT_MYSQL_PORT = '33118';

    private const DEFAULT_MYSQL_USER = 'rd_hw_fulf_test';

    private const DEFAULT_MYSQL_PASSWORD = 'rd_hw_fulf_test_pw';

    private const CALLBACK_EVENT_TYPE = HardwareFulfilmentCallbackOutboxWriter::EVENT_TYPE;

    private function phpBinary(): string
    {
        $candidate = '/opt/homebrew/bin/php';

        return is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    public function test_harness_enables_automatic_statutory_invoice_issuance_in_workers(): void
    {
        $context = $this->prepareContextOrSkip();

        $this->assertTrue($context['auto_invoice_harness']);
        $this->assertTrue($context['post_allocate_auto_statutory_invoice']);
        $this->assertTrue($context['location_series_enabled']);
        $this->assertFalse($context['channel_ingest_auto_issue_invoice']);
    }

    public function test_scenario_a_concurrent_identical_retries_mint_one_invoice_and_callbacks(): void
    {
        $context = $this->prepareContextOrSkip();
        $fulfilmentId = (int) $context['fulfilment_mixed_idem_id'];

        $results = $this->runWorkers($context, [
            [
                'fulfilment_id' => $fulfilmentId,
                'scanner_item_id' => $context['mixed_idem_scanner_item_id'],
                'serials' => [$context['independent_serial_b']],
            ],
            [
                'fulfilment_id' => $fulfilmentId,
                'scanner_item_id' => $context['mixed_idem_scanner_item_id'],
                'serials' => [$context['independent_serial_b']],
            ],
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertTrue($results[0]['post_allocate_auto_statutory_invoice'] && $results[1]['post_allocate_auto_statutory_invoice']);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued->value, $results[0]['state']);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued->value, $results[1]['state']);
        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $this->assertSame(1, $this->countSoldSerial($pdo, $context['independent_serial_b']));
        $this->assertSame(1, $this->invoiceCountForFulfilment($pdo, $fulfilmentId));
        $this->assertSame(1, $this->globalStatutoryInvoiceCount($pdo));
        $this->assertFulfilmentState($pdo, $fulfilmentId, HardwareFulfilmentState::InvoiceIssued);
        $this->assertSame(2, $this->callbackCountForFulfilment($pdo, $fulfilmentId));
        $this->assertNoDuplicateCallbackIdempotencyKeys($pdo, $fulfilmentId);
        $this->assertNoDuplicateHardwareSerialAllocations($pdo);
        $this->assertSame(2, (int) $pdo->query(
            'select available_qty from inventory_stock_balances where product_id = '.(int) $context['cable_product_id']
        )->fetchColumn());
    }

    public function test_scenario_b_different_serial_selections_reject_loser_without_extra_invoice(): void
    {
        $context = $this->prepareContextOrSkip();
        $fulfilmentId = (int) $context['fulfilment_mixed_idem_id'];

        $results = $this->runWorkers($context, [
            [
                'fulfilment_id' => $fulfilmentId,
                'scanner_item_id' => $context['mixed_idem_scanner_item_id'],
                'serials' => [$context['independent_serial_a']],
            ],
            [
                'fulfilment_id' => $fulfilmentId,
                'scanner_item_id' => $context['mixed_idem_scanner_item_id'],
                'serials' => [$context['independent_serial_b']],
            ],
        ]);

        $wins = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $losses = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));
        $this->assertCount(1, $wins, json_encode($results));
        $this->assertCount(1, $losses, json_encode($results));
        $this->assertIndependentConnections($results);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued->value, $wins[0]['state']);

        $pdo = $this->safePdo();
        $this->assertSame(1, $this->invoiceCountForFulfilment($pdo, $fulfilmentId));
        $this->assertSame(2, $this->callbackCountForFulfilment($pdo, $fulfilmentId));
        $this->assertSame(1, (int) $pdo->query(
            'select count(*) from hardware_fulfilment_serials where hardware_fulfilment_id = '.$fulfilmentId
        )->fetchColumn());
        $this->assertNoDuplicateHardwareSerialAllocations($pdo);
    }

    public function test_scenario_c_retry_after_invoice_is_idempotent_for_invoice_callbacks_and_stock(): void
    {
        $context = $this->prepareContextOrSkip();
        $fulfilmentId = (int) $context['fulfilment_mixed_idem_id'];
        $attempt = [
            'fulfilment_id' => $fulfilmentId,
            'scanner_item_id' => $context['mixed_idem_scanner_item_id'],
            'serials' => [$context['independent_serial_b']],
        ];

        $first = $this->runWorkers($context, [$attempt, $attempt]);
        $this->assertTrue($first[0]['ok'] && $first[1]['ok']);

        $pdo = $this->safePdo();
        $invoiceId = $this->fulfilmentStatutoryInvoiceId($pdo, $fulfilmentId);
        $this->assertNotNull($invoiceId);
        $soldBefore = $this->countSoldSerial($pdo, $context['independent_serial_b']);
        $callbacksBefore = $this->callbackCountForFulfilment($pdo, $fulfilmentId);

        $second = $this->runWorkers($context, [$attempt]);
        $this->assertTrue($second[0]['ok']);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued->value, $second[0]['state']);

        $pdo = $this->safePdo();
        $this->assertSame($invoiceId, $this->fulfilmentStatutoryInvoiceId($pdo, $fulfilmentId));
        $this->assertSame(1, $this->invoiceCountForFulfilment($pdo, $fulfilmentId));
        $this->assertSame($callbacksBefore, $this->callbackCountForFulfilment($pdo, $fulfilmentId));
        $this->assertSame($soldBefore, $this->countSoldSerial($pdo, $context['independent_serial_b']));
        $this->assertNoDuplicateCallbackIdempotencyKeys($pdo, $fulfilmentId);
    }

    public function test_scenario_d_failed_allocation_rolls_back_without_invoice_or_partial_serials(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'fulfilment_id' => $context['fulfilment_scan_a_id'],
                'scanner_item_id' => $context['scan_a_item_id'],
                'serials' => [$context['contended_serial']],
            ],
            [
                'fulfilment_id' => $context['fulfilment_rollback_id'],
                'scanner_item_id' => $context['rollback_scanner_item_id'],
                'serials' => [$context['contended_serial'], 'INNODB-HW-MISSING'],
            ],
        ]);

        $wins = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $losses = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));
        $this->assertCount(1, $wins);
        $this->assertCount(1, $losses);
        $this->assertIndependentConnections($results);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued->value, $wins[0]['state']);

        $pdo = $this->safePdo();
        $winnerId = (int) $context['fulfilment_scan_a_id'];
        $loserId = (int) $context['fulfilment_rollback_id'];

        $this->assertSame(1, $this->invoiceCountForFulfilment($pdo, $winnerId));
        $this->assertSame(0, $this->invoiceCountForFulfilment($pdo, $loserId));
        $this->assertSame(0, (int) $pdo->query(
            'select count(*) from hardware_fulfilment_serials where hardware_fulfilment_id = '.$loserId
        )->fetchColumn());
        $this->assertSame(1, $this->countSoldSerial($pdo, $context['contended_serial']));
        $this->assertFulfilmentState($pdo, $loserId, HardwareFulfilmentState::ReadyForFulfilment);
        $this->assertSame(0, (int) $pdo->query(
            'select count(*) from outbox_events where aggregate_id = '.$loserId
            ." and event_type = '".$this->pdoQuote(self::CALLBACK_EVENT_TYPE)."'"
        )->fetchColumn());
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(): array
    {
        $this->assertSafeMysqlTarget();
        $pdo = $this->probeSafeMysqlOrSkip();
        $this->assertSame(self::SAFE_DATABASE, (string) $pdo->query('select database()')->fetchColumn());
        unset($pdo);
        DB::disconnect('mysql');

        $prepare = $this->process(
            [$this->phpBinary(), base_path('tests/Feature/HardwareFulfilment/Support/mysql_hardware_fulfilment_prepare.php')],
            300
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
        $database = getenv('HARDWARE_FULFILMENT_MYSQL_DATABASE') ?: self::SAFE_DATABASE;

        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedHost($host));
        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('HARDWARE_FULFILMENT_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('HARDWARE_FULFILMENT_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT;
        $database = getenv('HARDWARE_FULFILMENT_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('HARDWARE_FULFILMENT_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER;
        $password = getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD') !== false
            ? (string) getenv('HARDWARE_FULFILMENT_MYSQL_PASSWORD')
            : self::DEFAULT_MYSQL_PASSWORD;

        if ($port === '' || $port === '3306') {
            $this->markTestSkipped(
                'MariaDB test environment unavailable → BLOCKER. Need disposable '.self::SAFE_DATABASE
                .' on loopback non-3306 port.'
            );
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped(
                'MariaDB test environment unavailable → BLOCKER. '.$exception->getMessage()
            );
        }

        return $pdo;
    }

    private function safePdo(): PDO
    {
        return $this->probeSafeMysqlOrSkip();
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

    private function assertNoDuplicateHardwareSerialAllocations(PDO $pdo): void
    {
        $dupes = (int) $pdo->query(
            'select count(*) from (select inventory_serial_id from hardware_fulfilment_serials where status = \'allocated\' group by inventory_serial_id having count(*) > 1) d'
        )->fetchColumn();
        $this->assertSame(0, $dupes);
    }

    private function assertNoDuplicateCallbackIdempotencyKeys(PDO $pdo, int $fulfilmentId): void
    {
        $dupes = (int) $pdo->query(
            'select count(*) from (select idempotency_key from outbox_events where aggregate_id = '.$fulfilmentId
            ." and event_type = '".$this->pdoQuote(self::CALLBACK_EVENT_TYPE)."'"
            .' group by idempotency_key having count(*) > 1) d'
        )->fetchColumn();
        $this->assertSame(0, $dupes);
    }

    private function countSoldSerial(PDO $pdo, string $serial): int
    {
        return (int) $pdo->query(
            "select count(*) from inventory_serials where serial_number = '".$this->pdoQuote($serial)."' and status = 'sold'"
        )->fetchColumn();
    }

    private function globalStatutoryInvoiceCount(PDO $pdo): int
    {
        return (int) $pdo->query('select count(*) from statutory_invoices')->fetchColumn();
    }

    private function invoiceCountForFulfilment(PDO $pdo, int $fulfilmentId): int
    {
        $invoiceId = $this->fulfilmentStatutoryInvoiceId($pdo, $fulfilmentId);

        return $invoiceId === null ? 0 : 1;
    }

    private function fulfilmentStatutoryInvoiceId(PDO $pdo, int $fulfilmentId): ?int
    {
        $value = $pdo->query(
            'select statutory_invoice_id from hardware_fulfilments where id = '.$fulfilmentId
        )->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function callbackCountForFulfilment(PDO $pdo, int $fulfilmentId): int
    {
        return (int) $pdo->query(
            'select count(*) from outbox_events where aggregate_id = '.$fulfilmentId
            ." and event_type = '".$this->pdoQuote(self::CALLBACK_EVENT_TYPE)."'"
        )->fetchColumn();
    }

    private function assertFulfilmentState(PDO $pdo, int $fulfilmentId, HardwareFulfilmentState $state): void
    {
        $actual = (string) $pdo->query(
            'select state from hardware_fulfilments where id = '.$fulfilmentId
        )->fetchColumn();
        $this->assertSame($state->value, $actual);
    }

    private function pdoQuote(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $attempts
     * @return list<array<string, mixed>>
     */
    private function runWorkers(array $context, array $attempts): array
    {
        $dir = sys_get_temp_dir().'/hardware-fulfilment-mysql-auto-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        $go = $dir.'/go';
        $processes = [];
        $resultPaths = [];

        foreach ($attempts as $index => $attempt) {
            $payloadPath = $dir.'/payload-'.$index.'.json';
            $readyPath = $dir.'/ready-'.$index;
            $resultPath = $dir.'/result-'.$index.'.json';
            $resultPaths[] = $resultPath;

            $payload = array_merge([
                'actor_id' => $context['actor_id'],
                'action' => 'allocate_mixed',
            ], $attempt);
            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = $this->process([
                $this->phpBinary(),
                base_path('tests/Feature/HardwareFulfilment/Support/mysql_hardware_fulfilment_auto_invoice_worker.php'),
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
        $env['HARDWARE_FULFILMENT_MYSQL_DATABASE'] = self::SAFE_DATABASE;
        $env['HARDWARE_FULFILMENT_MYSQL_USERNAME'] = $username;
        $env['HARDWARE_FULFILMENT_MYSQL_PASSWORD'] = $password;
        $env['HARDWARE_FULFILMENT_MYSQL_AUTO_INVOICE'] = '1';
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
