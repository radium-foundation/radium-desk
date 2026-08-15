<?php

namespace Tests\Feature\DatabaseSync;

use App\Console\Commands\DatabaseDeltaSyncCommand;
use App\Infrastructure\DatabaseSync\DatabaseSyncApplyService;
use App\Infrastructure\DatabaseSync\DatabaseSyncDryRunService;
use App\Infrastructure\DatabaseSync\DatabaseSyncManifest;
use App\Infrastructure\DatabaseSync\SchemaParityResult;
use App\Infrastructure\DatabaseSync\SyncVerificationReport;
use App\Infrastructure\DatabaseSync\TableProbeResult;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class DatabaseDeltaSyncApplyCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_apply_is_refused_without_explicit_apply_flag(): void
    {
        $this->artisan('db:sync-delta --vps-is-dark')
            ->expectsOutputToContain('dry-run only')
            ->assertFailed();
    }

    public function test_apply_requires_vps_is_dark(): void
    {
        $this->artisan('db:sync-delta --apply')
            ->expectsOutputToContain('--vps-is-dark')
            ->assertFailed();
    }

    public function test_apply_mode_invokes_apply_service_when_gates_pass(): void
    {
        $this->mock(DatabaseSyncApplyService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(null, null, null)
                ->andReturn([
                    'generation_id' => '20260815T130500Z',
                    'direction' => 'hostinger_to_vps',
                ]);
        });

        $this->app->forgetInstance(DatabaseDeltaSyncCommand::class);

        $this->artisan('db:sync-delta --apply --vps-is-dark --json')
            ->assertSuccessful();
    }

    public function test_direction_cannot_be_reversed(): void
    {
        $config = config('database-sync');
        $config['direction'] = 'vps_to_hostinger';
        config(['database-sync' => $config]);

        $this->expectException(InvalidArgumentException::class);

        new DatabaseSyncManifest;
    }
}
