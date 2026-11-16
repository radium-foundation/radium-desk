<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mariadb')]
class RdExpiryServiceReferenceMariaDbConcurrencyTest extends TestCase
{
    private const DATABASE = 'radium_desk_rd_exp_sr_concurrency';

    protected function tearDown(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        parent::tearDown();
    }

    public function test_parallel_probe_scripts_allocate_unique_sequences(): void
    {
        if (! $this->mysqlReachable()) {
            $this->markTestSkipped('Local MariaDB/MySQL is unavailable.');
        }

        $this->prepareMysqlDatabase();

        $probe = base_path('tests/Support/rd_exp_sr_allocate_probe.php');
        $phpBinary = '/opt/homebrew/bin/php';
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => self::DATABASE,
            'DB_USERNAME' => env('DB_USERNAME', 'root'),
            'DB_PASSWORD' => env('DB_PASSWORD', ''),
        ];

        $processes = [];
        foreach ([1, 2, 3] as $_) {
            $process = new Process([$phpBinary, '-d', 'auto_prepend_file=', $probe], base_path(), $env);
            $process->start();
            $processes[] = $process;
        }

        $references = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $references[] = trim($process->getOutput());
        }

        $this->assertCount(3, array_unique($references));
        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^AW-15-07-27-0[1-3]$/', $reference);
        }
    }

    private function mysqlReachable(): bool
    {
        try {
            new PDO(
                'mysql:host=127.0.0.1',
                env('DB_USERNAME', 'root'),
                env('DB_PASSWORD', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function prepareMysqlDatabase(): void
    {
        $pdo = new PDO(
            'mysql:host=127.0.0.1',
            env('DB_USERNAME', 'root'),
            env('DB_PASSWORD', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('DROP DATABASE IF EXISTS `'.self::DATABASE.'`');
        $pdo->exec('CREATE DATABASE `'.self::DATABASE.'`');

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => self::DATABASE,
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');

        Schema::dropIfExists('dashboard_oem_handoff_sessions');
        Schema::dropIfExists('rd_expiry_service_reference_sequences');
        Schema::dropIfExists('orders');

        Schema::create('orders', function ($table): void {
            $table->id();
            $table->string('transaction_id', 100)->nullable()->index();
            $table->softDeletes();
        });

        Schema::create('rd_expiry_service_reference_sequences', function ($table): void {
            $table->id();
            $table->date('expiry_date')->unique('rd_exp_sr_seq_expiry_unique');
            $table->unsignedInteger('last_allocated_sequence')->default(0);
            $table->timestamps();
        });

        Schema::create('dashboard_oem_handoff_sessions', function ($table): void {
            $table->id();
            $table->string('suggested_service_reference', 32)->nullable()->index();
        });

        DB::table('rd_expiry_service_reference_sequences')->insert([
            'expiry_date' => '2027-07-15',
            'last_allocated_sequence' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}