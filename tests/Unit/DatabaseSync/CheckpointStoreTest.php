<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\CheckpointStore;
use Tests\TestCase;

class CheckpointStoreTest extends TestCase
{
    public function test_write_and_read_round_trip_is_atomic(): void
    {
        $path = storage_path('framework/testing/db-sync-'.uniqid('', true).'.json');
        config(['database-sync.checkpoint_path' => $path]);

        try {
            $store = new CheckpointStore;

            $this->assertSame('hostinger_to_vps', $store->read()['direction']);

            $store->recordDryRun([
                'generated_at' => '2026-08-15T12:00:00+05:30',
                'table_count' => 3,
                'warnings' => 1,
                'blockers' => 0,
                'schema_parity_matched' => true,
            ]);

            $state = $store->read();

            $this->assertSame('2026-08-15T12:00:00+05:30', $state['last_dry_run']['generated_at'] ?? null);
            $this->assertIsString($state['last_dry_run_at'] ?? null);
            $this->assertFileDoesNotExist($path.'.tmp');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
