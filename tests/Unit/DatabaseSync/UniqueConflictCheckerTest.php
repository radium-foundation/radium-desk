<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\SyncTableDefinition;
use App\Infrastructure\DatabaseSync\UniqueConflictChecker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UniqueConflictCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('cashfree_payment_id')->nullable()->unique();
            $table->string('serial_number')->nullable();
            $table->timestamps();
        });

        Schema::create('cashfree_webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('cf_payment_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cashfree_webhook_logs');
        Schema::dropIfExists('orders');

        parent::tearDown();
    }

    public function test_unique_index_nested_list_parsing(): void
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
        $this->assertSame($definition->businessUniqueKeys, $definition->uniqueIndexes);
    }

    public function test_business_unique_conflict_with_different_pk_aborts(): void
    {
        DB::table('orders')->insert([
            'id' => 1,
            'order_id' => 'ORD-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definition = $this->ordersDefinition();
        $checker = new UniqueConflictChecker;

        $conflict = $checker->detectConflict($definition, [
            'id' => 2,
            'order_id' => 'ORD-1',
            'cashfree_payment_id' => null,
            'serial_number' => null,
        ]);

        $this->assertNotNull($conflict);
        $this->assertSame(['order_id'], $conflict['unique_index']);
        $this->assertSame(['id' => 2], $conflict['source_pk']);
        $this->assertSame(['id' => 1], $conflict['target_pk']);
    }

    public function test_same_pk_update_is_allowed(): void
    {
        DB::table('orders')->insert([
            'id' => 1,
            'order_id' => 'ORD-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definition = $this->ordersDefinition();
        $checker = new UniqueConflictChecker;

        $conflict = $checker->detectConflict($definition, [
            'id' => 1,
            'order_id' => 'ORD-1',
            'cashfree_payment_id' => null,
            'serial_number' => null,
        ]);

        $this->assertNull($conflict);
    }

    public function test_nullable_serial_number_does_not_create_false_conflicts(): void
    {
        DB::table('orders')->insert([
            'id' => 1,
            'order_id' => 'ORD-1',
            'serial_number' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definition = $this->ordersDefinition();
        $checker = new UniqueConflictChecker;

        $conflict = $checker->detectConflict($definition, [
            'id' => 2,
            'order_id' => 'ORD-2',
            'cashfree_payment_id' => null,
            'serial_number' => null,
        ]);

        $this->assertNull($conflict);
    }

    public function test_duplicate_serial_number_across_different_pks_is_allowed(): void
    {
        DB::table('orders')->insert([
            'id' => 1,
            'order_id' => 'ORD-1',
            'serial_number' => 'SN-SHARED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definition = $this->ordersDefinition();
        $checker = new UniqueConflictChecker;

        $conflict = $checker->detectConflict($definition, [
            'id' => 2,
            'order_id' => 'ORD-2',
            'cashfree_payment_id' => null,
            'serial_number' => 'SN-SHARED',
        ]);

        $this->assertNull($conflict);
    }

    public function test_cashfree_payment_id_conflict_with_different_pk_aborts(): void
    {
        DB::table('orders')->insert([
            'id' => 1,
            'order_id' => 'ORD-1',
            'cashfree_payment_id' => 'pay-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definition = $this->ordersDefinition();
        $checker = new UniqueConflictChecker;

        $conflict = $checker->detectConflict($definition, [
            'id' => 2,
            'order_id' => 'ORD-2',
            'cashfree_payment_id' => 'pay-1',
            'serial_number' => null,
        ]);

        $this->assertNotNull($conflict);
        $this->assertSame(['cashfree_payment_id'], $conflict['unique_index']);
        $this->assertSame(['id' => 2], $conflict['source_pk']);
        $this->assertSame(['id' => 1], $conflict['target_pk']);
    }

    public function test_cashfree_webhook_logs_cf_payment_id_is_not_treated_as_unique(): void
    {
        $definition = SyncTableDefinition::fromConfig('cashfree_webhook_logs', config('database-sync.tables.cashfree_webhook_logs'));

        $this->assertSame([], $definition->uniqueIndexes);
        $this->assertSame([], $definition->businessUniqueKeys);

        DB::table('cashfree_webhook_logs')->insert([
            ['id' => 1, 'cf_payment_id' => 'pay-1', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'cf_payment_id' => 'pay-1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $checker = new UniqueConflictChecker;

        $conflict = $checker->detectConflict($definition, [
            'id' => 3,
            'cf_payment_id' => 'pay-1',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->assertNull($conflict);
    }

    private function ordersDefinition(): SyncTableDefinition
    {
        return SyncTableDefinition::fromConfig('orders', config('database-sync.tables.orders'));
    }
}
