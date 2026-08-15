<?php

namespace Tests\Feature\DatabaseSync;

use App\Console\Commands\DatabaseDeltaSyncCommand;
use App\Infrastructure\DatabaseSync\DatabaseSyncDryRunService;
use App\Infrastructure\DatabaseSync\SchemaParityResult;
use App\Infrastructure\DatabaseSync\SyncVerificationReport;
use App\Infrastructure\DatabaseSync\TableProbeResult;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class DatabaseDeltaSyncCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('db:sync-delta --help')
            ->assertSuccessful();
    }

    public function test_command_rejects_execution_without_dry_run(): void
    {
        $this->artisan('db:sync-delta')
            ->expectsOutputToContain('dry-run only')
            ->assertFailed();
    }

    public function test_dry_run_outputs_json_report_without_database_writes(): void
    {
        $report = new SyncVerificationReport(
            generatedAt: '2026-08-15T12:00:00+05:30',
            direction: 'hostinger_to_vps',
            source: ['name' => 'hostinger', 'label' => 'Hostinger Production', 'ssh_host' => '187.127.183.72', 'ssh_port' => 65002, 'ssh_user' => 'u215544208', 'project_path' => '/home/u215544208/laravel/radium-desk'],
            target: ['name' => 'vps', 'label' => 'VPS radium-1', 'ssh_host' => '148.113.8.82', 'ssh_port' => 20097, 'ssh_user' => 'ravi', 'project_path' => '/var/www/radium-desk'],
            schemaParity: new SchemaParityResult(matched: true),
            tables: [[
                'table' => 'orders',
                'tier' => 1,
                'sync_order' => 30,
                'cursor_strategy' => 'bigint_id+updated_at',
                'primary_key' => ['id'],
                'unique_keys' => ['order_id'],
                'source' => (new TableProbeResult('hostinger', 'orders', 100, 1, 100, '2026-08-15 10:00:00'))->toArray(),
                'target' => (new TableProbeResult('vps', 'orders', 90, 1, 90, '2026-08-14 10:00:00'))->toArray(),
                'count_drift' => 10,
            ]],
            warnings: ['Table [orders] source is ahead by 10 row(s).'],
            blockers: [],
        );

        $this->mock(DatabaseSyncDryRunService::class, function ($mock) use ($report): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(null, null)
                ->andReturn($report);
        });

        $this->app->forgetInstance(DatabaseDeltaSyncCommand::class);

        $exitCode = Artisan::call('db:sync-delta', [
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('hostinger_to_vps', $output);
        $this->assertStringContainsString('count_drift', $output);
    }

    public function test_dry_run_human_output_makes_direction_explicit(): void
    {
        $this->mock(DatabaseSyncDryRunService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new SyncVerificationReport(
                    generatedAt: now()->toIso8601String(),
                    direction: 'hostinger_to_vps',
                    source: ['name' => 'hostinger', 'label' => 'Hostinger Production', 'ssh_host' => '187.127.183.72', 'ssh_port' => 65002, 'ssh_user' => 'u215544208', 'project_path' => '/path'],
                    target: ['name' => 'vps', 'label' => 'VPS radium-1', 'ssh_host' => '148.113.8.82', 'ssh_port' => 20097, 'ssh_user' => 'ravi', 'project_path' => '/var/www/radium-desk'],
                    schemaParity: new SchemaParityResult(matched: true),
                    tables: [],
                ));
        });

        $this->app->forgetInstance(DatabaseDeltaSyncCommand::class);

        $this->artisan('db:sync-delta --dry-run')
            ->expectsOutputToContain('Hostinger SOURCE → VPS TARGET')
            ->assertSuccessful();
    }
}
