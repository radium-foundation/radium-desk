<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\ChunkManifest;
use App\Infrastructure\DatabaseSync\SyncTableDefinition;
use App\Infrastructure\DatabaseSync\TableCheckpointStore;
use App\Infrastructure\DatabaseSync\TableDeltaApplier;
use App\Infrastructure\DatabaseSync\UniqueConflictChecker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReferenceSequenceMergeTest extends TestCase
{
    use IsolatesTableCheckpointDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateTableCheckpointDirectory();

        Schema::create('reference_sequences', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('current_value');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('reference_sequences');

        $this->cleanupTableCheckpointDirectory();

        parent::tearDown();
    }

    public function test_reference_sequences_uses_greatest_merge(): void
    {
        DB::table('reference_sequences')->insert([
            'name' => 'incident_ref',
            'current_value' => 100,
            'created_at' => '2026-08-15 10:00:00',
            'updated_at' => '2026-08-15 10:00:00',
        ]);

        $chunkPath = $this->writeChunk([
            [
                'name' => 'incident_ref',
                'current_value' => 150,
                'created_at' => '2026-08-15 11:00:00',
                'updated_at' => '2026-08-15 11:00:00',
            ],
        ]);

        $definition = SyncTableDefinition::fromConfig('reference_sequences', config('database-sync.tables.reference_sequences'));
        $applier = new TableDeltaApplier(new UniqueConflictChecker, new TableCheckpointStore);
        $chunk = new ChunkManifest('gen-seq', 'reference_sequences', 1, $chunkPath, hash_file('sha256', $chunkPath), 1);

        $applier->applyChunk($definition, $chunk, 'gen-seq');

        $row = DB::table('reference_sequences')->where('name', 'incident_ref')->first();

        $this->assertSame(150, (int) $row->current_value);
        $this->assertSame('2026-08-15 11:00:00', (string) $row->updated_at);
    }

    public function test_deleted_at_is_copied(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('cashfree_payment_id')->nullable();
            $table->string('serial_number')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        $deletedAt = '2026-08-15 12:30:00';
        $chunkPath = $this->writeChunk([
            [
                'id' => 5,
                'order_id' => 'ORD-5',
                'cashfree_payment_id' => null,
                'serial_number' => null,
                'deleted_at' => $deletedAt,
                'created_at' => '2026-08-15 10:00:00',
                'updated_at' => '2026-08-15 12:30:00',
            ],
        ]);

        $definition = SyncTableDefinition::fromConfig('orders', config('database-sync.tables.orders'));
        $applier = new TableDeltaApplier(new UniqueConflictChecker, new TableCheckpointStore);
        $chunk = new ChunkManifest('gen-del', 'orders', 1, $chunkPath, hash_file('sha256', $chunkPath), 1);

        $applier->applyChunk($definition, $chunk, 'gen-del');

        $row = DB::table('orders')->where('id', 5)->first();

        $this->assertSame($deletedAt, (string) $row->deleted_at);

        Schema::dropIfExists('orders');
    }

    public function test_foreign_key_checks_remain_enabled(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id')->unique();
            $table->timestamps();
        });

        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('reference_no')->unique();
            $table->timestamps();
        });

        $chunkPath = $this->writeChunk([
            [
                'id' => 99,
                'order_id' => 999,
                'reference_no' => 'INC-99',
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ],
        ]);

        $definition = SyncTableDefinition::fromConfig('incidents', config('database-sync.tables.incidents'));
        $applier = new TableDeltaApplier(new UniqueConflictChecker, new TableCheckpointStore);
        $chunk = new ChunkManifest('gen-fk', 'incidents', 1, $chunkPath, hash_file('sha256', $chunkPath), 1);

        $this->expectException(\Throwable::class);

        try {
            $applier->applyChunk($definition, $chunk, 'gen-fk');
        } finally {
            Schema::dropIfExists('incidents');
            Schema::dropIfExists('orders');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeChunk(array $rows): string
    {
        $path = storage_path('framework/testing/chunk-ref-'.uniqid('', true).'.ndjson.gz');
        $handle = gzopen($path, 'wb9');

        foreach ($rows as $row) {
            gzwrite($handle, json_encode($row).PHP_EOL);
        }

        gzclose($handle);

        return $path;
    }
}
