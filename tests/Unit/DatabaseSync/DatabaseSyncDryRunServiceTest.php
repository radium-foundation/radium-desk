<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\DatabaseSyncDryRunService;
use App\Infrastructure\DatabaseSync\RemoteEndpointProfile;
use App\Infrastructure\DatabaseSync\RemoteTableProbe;
use App\Infrastructure\DatabaseSync\SchemaParityGate;
use App\Infrastructure\DatabaseSync\SyncTableDefinition;
use App\Infrastructure\DatabaseSync\TableProbeResult;
use Mockery;
use Tests\TestCase;

class DatabaseSyncDryRunServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_builds_count_and_watermark_drift_report(): void
    {
        $path = storage_path('framework/testing/db-sync-service-'.uniqid('', true).'.json');
        config(['database-sync.checkpoint_path' => $path]);

        try {
            $probe = Mockery::mock(RemoteTableProbe::class);
            $parity = Mockery::mock(SchemaParityGate::class);

            $orders = SyncTableDefinition::fromConfig('orders', config('database-sync.tables.orders'));

            $probe->shouldReceive('probeTable')
                ->once()
                ->with(Mockery::type(RemoteEndpointProfile::class), Mockery::on(static fn (SyncTableDefinition $table) => $table->name === 'orders'))
                ->andReturn(new TableProbeResult('hostinger', 'orders', 12, 1, 12, '2026-08-15 12:00:00'));
            $probe->shouldReceive('probeTable')
                ->once()
                ->andReturn(new TableProbeResult('vps', 'orders', 10, 1, 10, '2026-08-14 12:00:00'));

            $parity->shouldReceive('compare')->once()->andReturn(new \App\Infrastructure\DatabaseSync\SchemaParityResult(matched: true));

            $service = new DatabaseSyncDryRunService(
                $probe,
                $parity,
                new \App\Infrastructure\DatabaseSync\CheckpointStore,
            );

            $report = $service->run('orders', null);

            $this->assertSame('hostinger_to_vps', $report->direction);
            $this->assertCount(1, $report->tables);
            $this->assertSame(2, $report->tables[0]['count_drift'] ?? null);
            $this->assertStringContainsString('orders', $report->warnings[0] ?? '');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
