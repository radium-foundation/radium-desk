<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\SyncCursorStrategy;
use App\Infrastructure\DatabaseSync\SyncTableDefinition;
use InvalidArgumentException;
use Tests\TestCase;

class SyncTableDefinitionTest extends TestCase
{
    public function test_builds_bigint_id_updated_at_definition(): void
    {
        $definition = SyncTableDefinition::fromConfig('orders', [
            'tier' => 1,
            'sync_order' => 30,
            'strategy' => 'bigint_id+updated_at',
            'primary_key' => ['id'],
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
            'unique_indexes' => [['order_id']],
        ]);

        $this->assertSame('orders', $definition->name);
        $this->assertSame(SyncCursorStrategy::BigintIdUpdatedAt, $definition->strategy);
        $this->assertTrue($definition->strategy->supportsMaxUpdatedAt());
        $this->assertTrue($definition->strategy->supportsMaxPrimaryKey());
        $this->assertSame([['order_id']], $definition->uniqueIndexes);
    }

    public function test_splits_physical_unique_indexes_from_business_unique_keys(): void
    {
        $definition = SyncTableDefinition::fromConfig('orders', [
            'tier' => 1,
            'sync_order' => 30,
            'strategy' => 'bigint_id+updated_at',
            'primary_key' => ['id'],
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
            'physical_unique_indexes' => [['order_id'], ['cashfree_payment_id']],
            'business_unique_keys' => [['order_id'], ['cashfree_payment_id'], ['serial_number']],
        ]);

        $this->assertSame([
            ['order_id'],
            ['cashfree_payment_id'],
        ], $definition->physicalUniqueIndexes);
        $this->assertSame([
            ['order_id'],
            ['cashfree_payment_id'],
            ['serial_number'],
        ], $definition->businessUniqueKeys);
        $this->assertSame($definition->businessUniqueKeys, $definition->uniqueIndexes);
    }

    public function test_finance_bank_accounts_has_no_name_unique_requirement(): void
    {
        $definition = SyncTableDefinition::fromConfig('finance_bank_accounts', config('database-sync.tables.finance_bank_accounts'));

        $this->assertSame([], $definition->physicalUniqueIndexes);
        $this->assertSame([], $definition->businessUniqueKeys);
    }

    public function test_device_model_aliases_uses_normalized_alias(): void
    {
        $definition = SyncTableDefinition::fromConfig('device_model_aliases', config('database-sync.tables.device_model_aliases'));

        $this->assertSame([['normalized_alias']], $definition->physicalUniqueIndexes);
        $this->assertSame([['normalized_alias']], $definition->businessUniqueKeys);
    }

    public function test_orders_serial_number_is_not_a_business_unique_key(): void
    {
        $definition = SyncTableDefinition::fromConfig('orders', config('database-sync.tables.orders'));

        $this->assertSame([
            ['order_id'],
            ['cashfree_payment_id'],
        ], $definition->physicalUniqueIndexes);
        $this->assertSame([
            ['order_id'],
            ['cashfree_payment_id'],
        ], $definition->businessUniqueKeys);
        $this->assertFalse(in_array('serial_number', $definition->flattenedUniqueKeys(), true));
    }

    public function test_builds_insert_only_definition(): void
    {
        $definition = SyncTableDefinition::fromConfig('audit_logs', [
            'tier' => 4,
            'sync_order' => 120,
            'strategy' => 'bigint_id_insert_only',
            'primary_key' => ['id'],
            'created_at' => 'created_at',
        ]);

        $this->assertFalse($definition->strategy->supportsMaxUpdatedAt());
        $this->assertTrue($definition->strategy->supportsMaxCreatedAt());
    }

    public function test_rejects_bigint_updated_at_without_required_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SyncTableDefinition::fromConfig('orders', [
            'tier' => 1,
            'sync_order' => 30,
            'strategy' => 'bigint_id+updated_at',
            'primary_key' => ['uuid'],
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
        ]);
    }

    public function test_builds_composite_pk_definition(): void
    {
        $definition = SyncTableDefinition::fromConfig('approval_incident', [
            'tier' => 2,
            'sync_order' => 50,
            'strategy' => 'composite_pk',
            'primary_key' => ['approval_number_id', 'incident_id'],
            'created_at' => 'created_at',
        ]);

        $this->assertSame(SyncCursorStrategy::CompositePk, $definition->strategy);
        $this->assertFalse($definition->strategy->supportsMaxPrimaryKey());
    }
}
