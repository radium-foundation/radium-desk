<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\DatabaseSyncManifest;
use App\Infrastructure\DatabaseSync\RemoteTableProbe;
use App\Infrastructure\DatabaseSync\SchemaIndexParityGate;
use Mockery;
use Tests\TestCase;

class SchemaIndexParityGateTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_physical_unique_index_present_on_both_hosts_passes(): void
    {
        $probe = Mockery::mock(RemoteTableProbe::class);
        $probe->shouldReceive('fetchTableIndexes')->twice()->andReturn([
            'id' => ['id'],
            'email' => ['email'],
        ]);

        $gate = new SchemaIndexParityGate($probe);

        $this->assertSame([], $gate->blockers($this->manifest([
            'users' => $this->usersConfig(['physical_unique_indexes' => [['email']]]),
        ])));
    }

    public function test_required_physical_unique_index_missing_blocks(): void
    {
        $probe = Mockery::mock(RemoteTableProbe::class);
        $probe->shouldReceive('fetchTableIndexes')->twice()->andReturn([
            'id' => ['id'],
        ]);

        $gate = new SchemaIndexParityGate($probe);
        $blockers = $gate->blockers($this->manifest([
            'users' => $this->usersConfig(['physical_unique_indexes' => [['email']]]),
        ]));

        $this->assertNotEmpty($blockers);
        $this->assertTrue(collect($blockers)->contains(fn (string $blocker): bool => str_contains($blocker, 'users(email)')));
    }

    public function test_business_only_key_without_physical_unique_index_does_not_block(): void
    {
        $probe = Mockery::mock(RemoteTableProbe::class);
        $probe->shouldReceive('fetchTableIndexes')->twice()->andReturn([
            'id' => ['id'],
            'order_id' => ['order_id'],
            'cashfree_payment_id' => ['cashfree_payment_id'],
        ]);

        $gate = new SchemaIndexParityGate($probe);

        $this->assertSame([], $gate->blockers($this->manifest([
            'orders' => [
                'tier' => 1,
                'sync_order' => 30,
                'strategy' => 'bigint_id+updated_at',
                'primary_key' => ['id'],
                'updated_at' => 'updated_at',
                'created_at' => 'created_at',
                'physical_unique_indexes' => [['order_id'], ['cashfree_payment_id']],
                'business_unique_keys' => [['order_id'], ['cashfree_payment_id'], ['serial_number']],
            ],
        ])));
    }

    public function test_legacy_unique_indexes_still_require_physical_parity(): void
    {
        $probe = Mockery::mock(RemoteTableProbe::class);
        $probe->shouldReceive('fetchTableIndexes')->twice()->andReturn([
            'id' => ['id'],
        ]);

        $gate = new SchemaIndexParityGate($probe);
        $blockers = $gate->blockers($this->manifest([
            'users' => $this->usersConfig(['unique_indexes' => [['email']]]),
        ]));

        $this->assertNotEmpty($blockers);
        $this->assertTrue(collect($blockers)->contains(fn (string $blocker): bool => str_contains($blocker, 'users(email)')));
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables
     */
    private function manifest(array $tables): DatabaseSyncManifest
    {
        $config = config('database-sync');
        $config['tables'] = $tables;

        return new DatabaseSyncManifest($config);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function usersConfig(array $overrides): array
    {
        return array_merge([
            'tier' => 1,
            'sync_order' => 10,
            'strategy' => 'bigint_id+updated_at',
            'primary_key' => ['id'],
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
        ], $overrides);
    }
}
