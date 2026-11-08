<?php

namespace Tests\Feature\Pos;

use App\Enums\InventoryMovementType;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Feature\Inventory\Support\InventoryPosMysqlGate;
use Tests\TestCase;

class PosCustomerRequestedSerialExchangeMysqlConcurrencyTest extends TestCase
{
    public const SAFE_DATABASE = InventoryPosMysqlGate::DATABASE;

    public function test_two_workers_contending_on_same_original_serial_one_succeeds(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'original_serial_number' => $context['original_serial'],
                'replacement_serial_number' => $context['replacement_serial'],
                'idempotency_key' => 'exch-orig-a',
            ],
            [
                'original_serial_number' => $context['original_serial'],
                'replacement_serial_number' => $context['replacement_serial_2'],
                'idempotency_key' => 'exch-orig-b',
            ],
        ]);

        $wins = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $losses = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));
        $this->assertCount(1, $wins);
        $this->assertCount(1, $losses);
        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $restoreType = InventoryMovementType::PosCustomerSerialExchangeRestore->value;
        $assignType = InventoryMovementType::PosCustomerSerialExchangeAssign->value;
        $restoreMovements = (int) $pdo->query(
            "select count(*) from inventory_movements where type = '{$restoreType}'"
        )->fetchColumn();
        $assignMovements = (int) $pdo->query(
            "select count(*) from inventory_movements where type = '{$assignType}'"
        )->fetchColumn();
        $executed = (int) $pdo->query(
            'select count(*) from pos_customer_requested_serial_exchanges where executed_at is not null'
        )->fetchColumn();

        $this->assertSame(1, $restoreMovements);
        $this->assertSame(1, $assignMovements);
        $this->assertSame(1, $executed);
        $this->assertNoDuplicateSerialOwnership($pdo);
    }

    public function test_two_workers_contending_on_same_replacement_serial_one_succeeds(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'original_serial_number' => $context['original_serial'],
                'replacement_serial_number' => $context['replacement_serial'],
                'idempotency_key' => 'exch-repl-a',
            ],
            [
                'original_serial_number' => $context['original_serial_2'],
                'replacement_serial_number' => $context['replacement_serial'],
                'idempotency_key' => 'exch-repl-b',
            ],
        ]);

        $wins = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $losses = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));
        $this->assertCount(1, $wins);
        $this->assertCount(1, $losses);
        $this->assertIndependentConnections($results);
        $this->assertSingleExchangeAuditTrail($this->safePdo());
    }

    public function test_same_idempotency_key_concurrent_execute_creates_one_audit_and_one_movement_pair(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'original_serial_number' => $context['original_serial'],
                'replacement_serial_number' => $context['replacement_serial'],
                'idempotency_key' => 'exch-idem-shared',
            ],
            [
                'original_serial_number' => $context['original_serial'],
                'replacement_serial_number' => $context['replacement_serial'],
                'idempotency_key' => 'exch-idem-shared',
            ],
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame($results[0]['exchange_id'], $results[1]['exchange_id']);
        $this->assertIndependentConnections($results);
        $this->assertSingleExchangeAuditTrail($this->safePdo());
    }

    public function test_competing_exchanges_on_different_serials_same_sale_both_succeed(): void
    {
        $context = $this->prepareContextOrSkip();

        $results = $this->runWorkers($context, [
            [
                'original_serial_number' => $context['original_serial'],
                'replacement_serial_number' => $context['replacement_serial'],
                'idempotency_key' => 'exch-sale-a',
            ],
            [
                'original_serial_number' => $context['original_serial_2'],
                'replacement_serial_number' => $context['replacement_serial_2'],
                'idempotency_key' => 'exch-sale-b',
            ],
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertIndependentConnections($results);

        $pdo = $this->safePdo();
        $executed = (int) $pdo->query(
            'select count(*) from pos_customer_requested_serial_exchanges where executed_at is not null'
        )->fetchColumn();
        $this->assertSame(2, $executed);
        $this->assertNoDuplicateSerialOwnership($pdo);
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
            [PHP_BINARY, base_path('tests/Feature/Pos/Support/mysql_serial_exchange_prepare.php')],
            240
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
        $this->assertIsArray($context);

        return $context;
    }

    private function assertSafeMysqlTarget(): void
    {
        $host = getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('INVENTORY_POS_MYSQL_DATABASE') ?: self::SAFE_DATABASE;

        $this->assertTrue(InventoryPosMysqlGate::isAllowedHost($host));
        $this->assertTrue(InventoryPosMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(InventoryPosMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('INVENTORY_POS_MYSQL_PORT') ?: '';
        $database = getenv('INVENTORY_POS_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('INVENTORY_POS_MYSQL_USERNAME') ?: '';
        $password = getenv('INVENTORY_POS_MYSQL_PASSWORD') !== false ? (string) getenv('INVENTORY_POS_MYSQL_PASSWORD') : '';

        if ($username === '' || $port === '' || $port === '3306') {
            $this->markTestSkipped(
                'MariaDB concurrency suite requires loopback InnoDB on a non-3306 port and '.self::SAFE_DATABASE
            );
        }

        try {
            return new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped('MariaDB test database unreachable: '.$exception->getMessage());
        }
    }

    private function safePdo(): PDO
    {
        return $this->probeSafeMysqlOrSkip();
    }

    private function assertSingleExchangeAuditTrail(PDO $pdo): void
    {
        $restoreType = InventoryMovementType::PosCustomerSerialExchangeRestore->value;
        $assignType = InventoryMovementType::PosCustomerSerialExchangeAssign->value;
        $restoreMovements = (int) $pdo->query(
            "select count(*) from inventory_movements where type = '{$restoreType}'"
        )->fetchColumn();
        $assignMovements = (int) $pdo->query(
            "select count(*) from inventory_movements where type = '{$assignType}'"
        )->fetchColumn();
        $executed = (int) $pdo->query(
            'select count(*) from pos_customer_requested_serial_exchanges where executed_at is not null'
        )->fetchColumn();

        $this->assertSame(1, $restoreMovements);
        $this->assertSame(1, $assignMovements);
        $this->assertSame(1, $executed);
        $this->assertNoDuplicateSerialOwnership($pdo);
    }

    private function assertNoDuplicateSerialOwnership(PDO $pdo): void
    {
        $duplicateAssignments = (int) $pdo->query(
            'select count(*) from (select serial_id from inventory_sale_serials group by serial_id having count(*) > 1) d'
        )->fetchColumn();
        $this->assertSame(0, $duplicateAssignments);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $attempts
     * @return list<array<string, mixed>>
     */
    private function runWorkers(array $context, array $attempts): array
    {
        $dir = sys_get_temp_dir().'/serial-exchange-mysql-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        $go = $dir.'/go';
        $processes = [];
        $resultPaths = [];

        foreach ($attempts as $index => $attempt) {
            $payloadPath = $dir.'/payload-'.$index.'.json';
            $readyPath = $dir.'/ready-'.$index;
            $resultPath = $dir.'/result-'.$index.'.json';
            $resultPaths[] = $resultPath;

            $payload = [
                'action' => 'execute',
                'actor_id' => $context['actor_id'],
                'sale_id' => $context['sale_id'],
                'original_serial_number' => $attempt['original_serial_number'],
                'replacement_serial_number' => $attempt['replacement_serial_number'],
                'idempotency_key' => $attempt['idempotency_key'],
                'physical_receipt_attestation_reason' => 'InnoDB concurrency attestation.',
            ];

            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = $this->process([
                PHP_BINARY,
                base_path('tests/Feature/Pos/Support/mysql_serial_exchange_worker.php'),
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
        $this->assertNotSame($results[0]['connection_id'] ?? null, $results[1]['connection_id'] ?? null);
        $this->assertNotEmpty($results[0]['connection_id'] ?? null);
        $this->assertNotEmpty($results[1]['connection_id'] ?? null);
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

        $host = (string) (getenv('INVENTORY_POS_MYSQL_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('INVENTORY_POS_MYSQL_PORT') ?: '');
        $username = (string) (getenv('INVENTORY_POS_MYSQL_USERNAME') ?: '');
        $password = getenv('INVENTORY_POS_MYSQL_PASSWORD') !== false ? (string) getenv('INVENTORY_POS_MYSQL_PASSWORD') : '';

        $env['INVENTORY_POS_MYSQL_HOST'] = $host;
        $env['INVENTORY_POS_MYSQL_PORT'] = $port;
        $env['INVENTORY_POS_MYSQL_DATABASE'] = self::SAFE_DATABASE;
        $env['INVENTORY_POS_MYSQL_USERNAME'] = $username;
        $env['INVENTORY_POS_MYSQL_PASSWORD'] = $password;
        $env['APP_ENV'] = 'testing';

        return new Process($command, base_path(), $env, null, $timeout);
    }
}
