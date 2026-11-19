<?php

namespace Tests\Feature\StatutoryInvoice;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\StatutoryInvoice\Support\BuyerGstB2cConversionMysqlGate;
use Tests\TestCase;

#[Group('permanent_regression')]
class BuyerGstB2cConversionMysqlConcurrencyTest extends TestCase
{
    public const SAFE_DATABASE = BuyerGstB2cConversionMysqlGate::DATABASE;

    private const DEFAULT_MYSQL_PORT = '33118';

    private const DEFAULT_MYSQL_USER = 'rd_buyer_gst_b2c_conversion_test';

    private const DEFAULT_MYSQL_PASSWORD = 'rd_buyer_gst_b2c_conversion_test_pw';

    private function phpBinary(): string
    {
        $candidate = '/opt/homebrew/bin/php';

        return is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    public function test_gate_allows_only_loopback_hosts_and_named_database(): void
    {
        $this->assertTrue(BuyerGstB2cConversionMysqlGate::isAllowedHost('127.0.0.1'));
        $this->assertFalse(BuyerGstB2cConversionMysqlGate::isAllowedHost('187.127.129.16'));
        $this->assertTrue(BuyerGstB2cConversionMysqlGate::isAllowedDatabase(self::SAFE_DATABASE));
        $this->assertFalse(BuyerGstB2cConversionMysqlGate::isAllowedDatabase('radium_desk'));
    }

    public function test_two_workers_concurrent_approval_single_conversion_record(): void
    {
        for ($run = 1; $run <= 2; $run++) {
            $context = $this->prepareContextOrSkip(['suffix' => 'c'.$run]);
            $results = $this->runWorkers($context, 2);
            $this->assertConcurrentOutcomes($results);
        }
    }

    /**
     * @param  array{suffix: string}  $options
     * @return array<string, mixed>
     */
    private function prepareContextOrSkip(array $options): array
    {
        $this->assertSafeMysqlTarget();
        $this->probeSafeMysqlOrSkip();

        $prepare = $this->process(
            [
                $this->phpBinary(),
                base_path('tests/Feature/StatutoryInvoice/Support/mysql_buyer_gst_b2c_conversion_prepare.php'),
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
        $dir = sys_get_temp_dir().'/buyer-gst-b2c-conc-'.bin2hex(random_bytes(6));
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
                base_path('tests/Feature/StatutoryInvoice/Support/mysql_buyer_gst_b2c_conversion_worker.php'),
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

        touch($go);

        foreach ($processes as $item) {
            $item['process']->wait();
            if (! $item['process']->isSuccessful()) {
                $this->fail('Worker failed: '.$item['process']->getErrorOutput().$item['process']->getOutput());
            }
        }

        $results = [];
        foreach ($resultPaths as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($decoded);
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertConcurrentOutcomes(array $results): void
    {
        $this->assertCount(2, $results);
        $this->assertIndependentConnections($results);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($result));
            $this->assertSame(1, $result['conversion_count'] ?? null);
            $this->assertFalse($result['issue_as_b2b'] ?? true);
            $this->assertTrue($result['buyer_gstin_for_mint_is_null'] ?? false);
        }

        $this->assertSame($results[0]['conversion_id'] ?? null, $results[1]['conversion_id'] ?? null);
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
        $host = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_HOST') ?: '127.0.0.1';
        $database = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_DATABASE') ?: self::SAFE_DATABASE;

        $this->assertTrue(BuyerGstB2cConversionMysqlGate::isAllowedHost($host));
        $this->assertTrue(BuyerGstB2cConversionMysqlGate::isAllowedDatabase($database));
        $this->assertTrue(BuyerGstB2cConversionMysqlGate::isAllowedAppEnv((string) (getenv('APP_ENV') ?: 'testing')));
    }

    private function probeSafeMysqlOrSkip(): PDO
    {
        $host = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT;
        $database = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_DATABASE') ?: self::SAFE_DATABASE;
        $username = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER;
        $password = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_PASSWORD') !== false
            ? (string) getenv('BUYER_GST_B2C_CONVERSION_MYSQL_PASSWORD')
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

        $host = (string) (getenv('BUYER_GST_B2C_CONVERSION_MYSQL_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('BUYER_GST_B2C_CONVERSION_MYSQL_PORT') ?: self::DEFAULT_MYSQL_PORT);
        $username = (string) (getenv('BUYER_GST_B2C_CONVERSION_MYSQL_USERNAME') ?: self::DEFAULT_MYSQL_USER);
        $password = getenv('BUYER_GST_B2C_CONVERSION_MYSQL_PASSWORD') !== false
            ? (string) getenv('BUYER_GST_B2C_CONVERSION_MYSQL_PASSWORD')
            : self::DEFAULT_MYSQL_PASSWORD;

        $env['BUYER_GST_B2C_CONVERSION_MYSQL_HOST'] = $host;
        $env['BUYER_GST_B2C_CONVERSION_MYSQL_PORT'] = $port;
        $env['BUYER_GST_B2C_CONVERSION_MYSQL_DATABASE'] = self::SAFE_DATABASE;
        $env['BUYER_GST_B2C_CONVERSION_MYSQL_USERNAME'] = $username;
        $env['BUYER_GST_B2C_CONVERSION_MYSQL_PASSWORD'] = $password;
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
