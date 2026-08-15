<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\DatabaseSyncManifest;
use App\Infrastructure\DatabaseSync\SyncCursorStrategy;
use InvalidArgumentException;
use Tests\TestCase;

class DatabaseSyncManifestTest extends TestCase
{
    public function test_default_manifest_loads_with_deterministic_sync_order(): void
    {
        $manifest = new DatabaseSyncManifest;

        $tables = $manifest->tablesInSyncOrder();
        $names = array_map(static fn ($table) => $table->name, $tables);

        $this->assertNotEmpty($names);
        $this->assertLessThan(
            array_search('incidents', $names, true),
            array_search('orders', $names, true),
        );
        $this->assertLessThan(
            array_search('remarks', $names, true),
            array_search('incidents', $names, true),
        );
        $this->assertLessThan(
            array_search('orders', $names, true),
            array_search('users', $names, true),
        );
    }

    public function test_rejects_unknown_dependency(): void
    {
        $config = config('database-sync');
        $config['tables']['orders']['depends_on'][] = 'missing_table';
        config(['database-sync' => $config]);

        $this->expectException(InvalidArgumentException::class);

        new DatabaseSyncManifest;
    }

    public function test_rejects_invalid_cursor_strategy(): void
    {
        $config = config('database-sync');
        $config['tables']['users']['strategy'] = 'invalid_strategy';
        config(['database-sync' => $config]);

        $this->expectException(InvalidArgumentException::class);

        new DatabaseSyncManifest;
    }

    public function test_rejects_excluded_tables_in_manifest(): void
    {
        $config = config('database-sync');
        $config['tables']['cache'] = [
            'tier' => 3,
            'sync_order' => 10,
            'strategy' => 'full_replace',
            'primary_key' => ['key'],
        ];
        config(['database-sync' => $config]);

        $this->expectException(InvalidArgumentException::class);

        new DatabaseSyncManifest;
    }

    public function test_rejects_dependency_with_higher_sync_order(): void
    {
        $config = config('database-sync');
        $config['tables']['orders']['sync_order'] = 5;
        config(['database-sync' => $config]);

        $this->expectException(InvalidArgumentException::class);

        new DatabaseSyncManifest;
    }

    public function test_rejects_reverse_direction(): void
    {
        $config = config('database-sync');
        $config['direction'] = 'vps_to_hostinger';
        config(['database-sync' => $config]);

        $this->expectException(InvalidArgumentException::class);

        new DatabaseSyncManifest;
    }

    public function test_filters_tables_by_tier(): void
    {
        $manifest = new DatabaseSyncManifest;
        $tierOne = $manifest->tablesForTier(1);

        $this->assertNotEmpty($tierOne);
        $this->assertTrue(collect($tierOne)->every(static fn ($table) => $table->tier === 1));
        $this->assertSame(
            SyncCursorStrategy::BigintIdUpdatedAt,
            $manifest->find('orders')?->strategy,
        );
    }
}
