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
        $this->assertLessThan(
            array_search('cashfree_webhook_logs', $names, true),
            array_search('incidents', $names, true),
        );
        $this->assertLessThan(
            array_search('finance_journal_lines', $names, true),
            array_search('finance_journals', $names, true),
        );
        $this->assertLessThan(
            array_search('customer_data_correction_items', $names, true),
            array_search('customer_data_corrections', $names, true),
        );
        $this->assertLessThan(
            array_search('commercial_service_restorations', $names, true),
            array_search('refund_requests', $names, true),
        );
        $this->assertLessThan(
            array_search('bonvoice_call_events', $names, true),
            array_search('bonvoice_webhook_logs', $names, true),
        );
        $this->assertLessThan(
            array_search('incident_bonvoice_call_links', $names, true),
            array_search('bonvoice_call_events', $names, true),
        );
        $this->assertLessThan(
            array_search('incident_bonvoice_call_links', $names, true),
            array_search('incidents', $names, true),
        );
        $this->assertSame(1, $manifest->find('bonvoice_webhook_logs')?->tier);
        $this->assertSame(1, $manifest->find('bonvoice_call_events')?->tier);
    }

    public function test_equal_sync_order_respects_depends_on_over_alphabetical_order(): void
    {
        $manifest = $this->manifestWithTables([
            'alpha_child' => $this->tableConfig(40, ['zeta_parent']),
            'zeta_parent' => $this->tableConfig(40),
        ]);

        $this->assertSame(['zeta_parent', 'alpha_child'], $this->orderedNames($manifest));
    }

    public function test_unrelated_equal_sync_order_tables_are_ordered_deterministically_by_name(): void
    {
        $manifest = $this->manifestWithTables([
            'zeta' => $this->tableConfig(10),
            'alpha' => $this->tableConfig(10),
            'mu' => $this->tableConfig(10),
        ]);

        $this->assertSame(['alpha', 'mu', 'zeta'], $this->orderedNames($manifest));
    }

    public function test_rejects_dependency_cycle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cycle');

        $this->manifestWithTables([
            'left_table' => $this->tableConfig(40, ['right_table']),
            'right_table' => $this->tableConfig(40, ['left_table']),
        ]);
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
        $this->expectExceptionMessage('sync_order must be greater than dependency');

        new DatabaseSyncManifest;
    }

    public function test_rejects_incident_links_depending_on_call_events_at_higher_sync_order(): void
    {
        $config = config('database-sync');
        $config['tables']['bonvoice_call_events']['sync_order'] = 80;
        $config['tables']['incident_bonvoice_call_links']['depends_on'] = ['incidents', 'bonvoice_call_events'];
        config(['database-sync' => $config]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sync_order must be greater than dependency');

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
        $this->assertContains('bonvoice_webhook_logs', array_map(static fn ($table) => $table->name, $tierOne));
        $this->assertContains('bonvoice_call_events', array_map(static fn ($table) => $table->name, $tierOne));
        $this->assertSame(
            SyncCursorStrategy::BigintIdUpdatedAt,
            $manifest->find('orders')?->strategy,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables
     */
    private function manifestWithTables(array $tables): DatabaseSyncManifest
    {
        $config = config('database-sync');
        $config['tables'] = $tables;

        return new DatabaseSyncManifest($config);
    }

    /**
     * @param  list<string>  $dependsOn
     * @return array<string, mixed>
     */
    private function tableConfig(int $syncOrder, array $dependsOn = []): array
    {
        return [
            'tier' => 1,
            'sync_order' => $syncOrder,
            'strategy' => 'bigint_id+updated_at',
            'primary_key' => ['id'],
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
            'depends_on' => $dependsOn,
        ];
    }

    /**
     * @return list<string>
     */
    private function orderedNames(DatabaseSyncManifest $manifest): array
    {
        return array_map(
            static fn ($table): string => $table->name,
            $manifest->tablesInSyncOrder(),
        );
    }
}
