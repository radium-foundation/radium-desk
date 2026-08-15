<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\DatabaseSyncManifest;
use App\Infrastructure\DatabaseSync\RemoteEndpointProfile;
use App\Infrastructure\DatabaseSync\RemoteTableProbe;
use App\Infrastructure\DatabaseSync\SchemaParityGate;
use Mockery;
use Tests\TestCase;

class SchemaParityGateTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_reports_blockers_when_migrations_differ(): void
    {
        $probe = Mockery::mock(RemoteTableProbe::class);
        $manifest = new DatabaseSyncManifest;

        $probe->shouldReceive('fetchMigrationStatus')
            ->once()
            ->with(Mockery::on(static fn (RemoteEndpointProfile $profile) => $profile->name === 'hostinger'))
            ->andReturn([
                '2026_01_01_000000_create_example_table' => 1,
            ]);

        $probe->shouldReceive('fetchMigrationStatus')
            ->once()
            ->with(Mockery::on(static fn (RemoteEndpointProfile $profile) => $profile->name === 'vps'))
            ->andReturn([]);

        $result = (new SchemaParityGate($probe))->compare($manifest);

        $this->assertFalse($result->matched);
        $this->assertNotEmpty($result->blockers);
        $this->assertStringContainsString('source only', $result->blockers[0]);
    }

    public function test_reports_warning_for_batch_mismatch_on_shared_migration(): void
    {
        $probe = Mockery::mock(RemoteTableProbe::class);
        $manifest = new DatabaseSyncManifest;

        $probe->shouldReceive('fetchMigrationStatus')
            ->once()
            ->andReturn(['2026_shared_migration' => 1]);
        $probe->shouldReceive('fetchMigrationStatus')
            ->once()
            ->andReturn(['2026_shared_migration' => 2]);

        $result = (new SchemaParityGate($probe))->compare($manifest);

        $this->assertTrue($result->matched);
        $this->assertNotEmpty($result->warnings);
    }
}
