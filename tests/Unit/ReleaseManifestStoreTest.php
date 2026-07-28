<?php

namespace Tests\Unit;

use App\Services\Release\ReleaseManifestStore;
use Tests\TestCase;

class ReleaseManifestStoreTest extends TestCase
{
    public function test_write_and_read_round_trip(): void
    {
        $path = storage_path('framework/testing/manifest-'.uniqid('', true).'.json');
        config(['app.release_manifest' => $path]);

        try {
            $store = new ReleaseManifestStore;
            $store->write([
                'version' => '4.0.1',
                'tag' => 'v4.0.1',
                'build' => 'abc1234',
                'deployed_at' => '2026-07-28T00:00:00+00:00',
                'release_date' => '2026-07-28',
            ]);

            $this->assertSame([
                'version' => '4.0.1',
                'tag' => 'v4.0.1',
                'build' => 'abc1234',
                'deployed_at' => '2026-07-28T00:00:00+00:00',
                'release_date' => '2026-07-28',
            ], $store->read());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
