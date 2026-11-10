<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\InventoryMovementType;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\HardwareFulfilment\Support\HardwareFulfilmentMysqlGate;
use Tests\TestCase;

class HardwareMixedFulfilmentMysqlConcurrencyTest extends TestCase
{
    public const SAFE_DATABASE = HardwareFulfilmentMysqlGate::DATABASE;

    private const DEFAULT_MYSQL_PORT = '33118';

    private const DEFAULT_MYSQL_USER = 'rd_hw_fulf_test';

    private const DEFAULT_MYSQL_PASSWORD = 'rd_hw_fulf_test_pw';

    private function phpBinary(): string
    {
        $candidate = '/opt/homebrew/bin/php';

        return is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    public function test_gate_allows_only_loopback_hosts_and_the_named_test_database(): void
    {
        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedHost('127.0.0.1'));
        $this->assertFalse(HardwareFulfilmentMysqlGate::isAllowedHost('rdservice.in'));
        $this->assertTrue(HardwareFulfilmentMysqlGate::isAllowedDatabase(self::SAFE_DATABASE));
        $this->assertFalse(HardwareFulfilmentMysqlGate::isAllowedDatabase('radium_desk_local'));
        $this->assertFalse(HardwareFulfilmentMysqlGate::isAllowedAppEnv('production'));
    }

    public function test_two_workers_contending_on_same_serial_only_one_mixed_order_succeeds(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'fulfilment_id' => $context['fulfilment_mixed_a_id'],
                'scanner_item_id' => $context['mixed_a_scanner_item_id'],
                'serials' => [$context['contended_serial']],
            ],
            [
                'fulfilment_id' => $context['fulfilment_mixed_b_id'],
                'scanner_item_id' => $context['mixed_b_scanner_item_id'],
                'serials' => [$context['contended_serial']],
            ],
        ]);

        $wins = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $losses = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));
        $this->assertCount(1, $wins, json_encode($results));
        $this->assertCount(1, $losses, json_encode($results));
        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $this->assertSame(1, $this->countSoldSerial($pdo, $context['contended_serial']));
        $this->assertSame(1, (int) $pdo->query("select count(*) from hardware_fulfilment_serials where status = 'allocated'")->fetchColumn());
        $this->assertNoDuplicateHardwareSerialAllocations($pdo);
        $this->assertNoNegativeBalances($pdo);
    }

    public function test_two_quantity_only_fulfilments_cannot_oversell_stock(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'action' => 'allocate_quantity',
                'fulfilment_id' => $context['fulfilment_cable_c_id'],
            ],
            [
                'action' => 'allocate_quantity',
                'fulfilment_id' => $context['fulfilment_cable_d_id'],
            ],
        ]);

        $wins = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $losses = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));
        $this->assertCount(1, $wins, json_encode($results));
        $this->assertCount(1, $losses, json_encode($results));
        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $available = (int) $pdo->query(
            'select available_qty from inventory_stock_balances where product_id = '.(int) $context['cable_product_id']
        )->fetchColumn();
        $this->assertSame(1, $available);
        $this->assertSame(1, (int) $pdo->query(
            "select count(*) from hardware_fulfilments where state = '".$this->pdoQuote(HardwareFulfilmentState::SerialsAllocated->value)."'"
        )->fetchColumn());
        $this->assertNoNegativeBalances($pdo);
    }

    public function test_concurrent_mixed_allocation_on_same_order_commits_once(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'fulfilment_id' => $context['fulfilment_mixed_idem_id'],
                'scanner_item_id' => $context['mixed_idem_scanner_item_id'],
                'serials' => [$context['independent_serial_a']],
            ],
            [
                'fulfilment_id' => $context['fulfilment_mixed_idem_id'],
                'scanner_item_id' => $context['mixed_idem_scanner_item_id'],
                'serials' => [$context['independent_serial_a']],
            ],
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame($results[0]['fulfilment_id'], $results[1]['fulfilment_id']);
        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $this->assertSame(1, $this->countSoldSerial($pdo, $context['independent_serial_a']));
        $this->assertSame(-1, (int) $pdo->query(
            'select coalesce(sum(qty), 0) from inventory_movements where product_id = '.(int) $context['scanner_product_id']
            ." and type = '".$this->pdoQuote(InventoryMovementType::Sale->value)."'"
        )->fetchColumn());
        $this->assertSame(-1, (int) $pdo->query(
            'select coalesce(sum(qty), 0) from inventory_movements where product_id = '.(int) $context['cable_product_id']
            ." and type = '".$this->pdoQuote(InventoryMovementType::Sale->value)."'"
        )->fetchColumn());
        $this->assertNoDuplicateHardwareSerialAllocations($pdo);
    }

    public function test_failed_allocation_rolls_back_without_consuming_contended_serial(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'fulfilment_id' => $context['fulfilment_scan_a_id'],
                'scanner_item_id' => $context['scan_a_item_id'],
                'serials' => [$context['contended_serial']],
            ],
            [
                'action' => 'allocate_mixed',
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

        $pdo = $this->safePdo();
        $this->assertSame(1, $this->countSoldSerial($pdo, $context['contended_serial']));
        $this->assertSame(1, (int) $pdo->query(
            "select count(*) from hardware_fulfilment_serials where serial_number = '".$this->pdoQuote($context['contended_serial'])."'"
            ." and status = '".$this->pdoQuote(HardwareFulfilmentSerialStatus::Allocated->value)."'"
        )->fetchColumn());
        $this->assertSame(0, (int) $pdo->query(
            'select count(*) from hardware_fulfilment_serials where hardware_fulfilment_id = '.(int) $context['fulfilment_rollback_id']
        )->fetchColumn());
    }

    public function test_concurrent_idempotent_retries_do_not_double_consume_stock(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'fulfilment_id' => $context['fulfilment_mixed_idem_id'],
                'scanner_item_id' => $context['mixed_idem_scanner_item_id'],
                'serials' => [$context['independent_serial_b']],
            ],
            [
                'fulfilment_id' => $context['fulfilment_mixed_idem_id'],
                'scanner_item_id' => $context['mixed_idem_scanner_item_id'],
                'serials' => [$context['independent_serial_b']],
            ],
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok']);
        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $this->assertSame(1, $this->countSoldSerial($pdo, $context['independent_serial_b']));
        $this->assertSame(2, (int) $pdo->query(
            'select available_qty from inventory_stock_balances where product_id = '.(int) $context['cable_product_id']
        )->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('select count(*) from statutory_invoices')->fetchColumn());
    }

    public function test_separate_scanner_orders_race_on_serial_respects_availability(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'fulfilment_id' => $context['fulfilment_scan_a_id'],
                'scanner_item_id' => $context['scan_a_item_id'],
                'serials' => [$context['independent_serial_a']],
            ],
            [
                'fulfilment_id' => $context['fulfilment_scan_b_id'],
                'scanner_item_id' => $context['scan_b_item_id'],
                'serials' => [$context['independent_serial_a']],
            ],
        ]);

        $wins = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $losses = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));
        $this->assertCount(1, $wins);
        $this->assertCount(1, $losses);
        $this->assertIndependentConnections($results);
        $this->assertNoDuplicateHardwareSerialAllocations($this->safePdo());
    }

    public function test_after_concurrent_allocation_no_statutory_invoice_and_state_gate_holds(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'fulfilment_id' => $context['fulfilment_mixed_a_id'],
                'scanner_item_id' => $context['mixed_a_scanner_item_id'],
                'serials' => [$context['independent_serial_a']],
            ],
            [
                'fulfilment_id' => $context['fulfilment_mixed_b_id'],
                'scanner_item_id' => $context['mixed_b_scanner_item_id'],
                'serials' => [$context['independent_serial_b']],
            ],
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));

        $pdo = $this->safePdo();
        $this->assertSame(0, (int) $pdo->query('select count(*) from statutory_invoices')->fetchColumn());
        $allocatedStates = (int) $pdo->query(
            "select count(*) from hardware_fulfilments where state = '".$this->pdoQuote(HardwareFulfilmentState::SerialsAllocated->value)."'"
        )->fetchColumn();
        $this->assertSame(2, $allocatedStates);
        $this->assertNoNegativeBalances($pdo);
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(): array
    {
        $this->assertSafeMysqlTarget();
        $pdo = $this->probeSafeMysqlOrSkip();
        $this->assertSame(self::SAFE_DATABASE, (string) $pdo->query('select database()')->fetchColumn());

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
        $this->assertNotSame($results[0]['connection_id'] ?? null, $results[1]['connection_id'] ?? null);
        $this->assertNotEmpty($results[0]['connection_id'] ?? null);
        $this->assertNotEmpty($results[1]['connection_id'] ?? null);
    }

    private function assertNoNegativeBalances(PDO $pdo): void
    {
        $negative = (int) $pdo->query('select count(*) from inventory_stock_balances where available_qty < 0 or reserved_qty < 0')->fetchColumn();
        $this->assertSame(0, $negative);
    }

    private function assertNoDuplicateHardwareSerialAllocations(PDO $pdo): void
    {
        $dupes = (int) $pdo->query(
            'select count(*) from (select inventory_serial_id from hardware_fulfilment_serials where status = \'allocated\' group by inventory_serial_id having count(*) > 1) d'
        )->fetchColumn();
        $this->assertSame(0, $dupes);
    }

    private function countSoldSerial(PDO $pdo, string $serial): int
    {
        return (int) $pdo->query(
            "select count(*) from inventory_serials where serial_number = '".$this->pdoQuote($serial)."' and status = 'sold'"
        )->fetchColumn();
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
        $dir = sys_get_temp_dir().'/hardware-fulfilment-mysql-'.bin2hex(random_bytes(6));
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
                base_path('tests/Feature/HardwareFulfilment/Support/mysql_hardware_fulfilment_worker.php'),
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
