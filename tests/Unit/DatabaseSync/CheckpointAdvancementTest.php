<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\ChunkManifest;
use App\Infrastructure\DatabaseSync\SyncCursorStrategy;
use App\Infrastructure\DatabaseSync\SyncTableDefinition;
use App\Infrastructure\DatabaseSync\TableCheckpointStore;
use App\Infrastructure\DatabaseSync\TableDeltaApplier;
use App\Infrastructure\DatabaseSync\UniqueConflictChecker;
use App\Infrastructure\DatabaseSync\UniqueConflictException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CheckpointAdvancementTest extends TestCase
{
    private string $checkpointDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkpointDirectory = storage_path('framework/testing/checkpoints-'.uniqid('', true));
        config(['database-sync.table_checkpoint_directory' => $this->checkpointDirectory]);

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('cashfree_payment_id')->nullable()->unique();
            $table->string('serial_number')->nullable()->unique();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');

        if (is_dir($this->checkpointDirectory)) {
            array_map('unlink', glob($this->checkpointDirectory.'/*') ?: []);
            rmdir($this->checkpointDirectory);
        }

        parent::tearDown();
    }

    public function test_failed_transaction_does_not_advance_checkpoint(): void
    {
        DB::table('orders')->insert([
            'id' => 1,
            'order_id' => 'ORD-EXISTING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $chunkPath = $this->writeChunk([
            ['id' => 2, 'order_id' => 'ORD-EXISTING', 'cashfree_payment_id' => null, 'serial_number' => null, 'deleted_at' => null, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
        ]);

        $applier = new TableDeltaApplier(new UniqueConflictChecker, new TableCheckpointStore);
        $table = $this->ordersDefinition();
        $chunk = new ChunkManifest('gen-1', 'orders', 1, $chunkPath, hash_file('sha256', $chunkPath), 1);

        try {
            $applier->applyChunk($table, $chunk, 'gen-1');
            $this->fail('Expected unique conflict exception.');
        } catch (UniqueConflictException) {
            // expected
        }

        $checkpoint = (new TableCheckpointStore)->read('orders');
        $this->assertSame(0, $checkpoint['last_id']);
        $this->assertNull($checkpoint['last_generation_id']);
    }

    public function test_successful_transaction_advances_checkpoint_atomically(): void
    {
        $chunkPath = $this->writeChunk([
            ['id' => 10, 'order_id' => 'ORD-10', 'cashfree_payment_id' => null, 'serial_number' => null, 'deleted_at' => null, 'created_at' => '2026-08-15 10:00:00', 'updated_at' => '2026-08-15 10:00:00'],
        ]);

        $applier = new TableDeltaApplier(new UniqueConflictChecker, new TableCheckpointStore);
        $table = $this->ordersDefinition();
        $chunk = new ChunkManifest('gen-2', 'orders', 1, $chunkPath, hash_file('sha256', $chunkPath), 1);

        $applier->applyChunk($table, $chunk, 'gen-2');

        $checkpoint = (new TableCheckpointStore)->read('orders');

        $this->assertSame(10, $checkpoint['last_id']);
        $this->assertSame('gen-2', $checkpoint['last_generation_id']);
        $this->assertSame($chunk->sha256, $checkpoint['last_chunk_checksum']);
        $this->assertIsString($checkpoint['applied_at']);
        $this->assertFileDoesNotExist($this->checkpointDirectory.'/orders.json.tmp');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeChunk(array $rows): string
    {
        $path = storage_path('framework/testing/chunk-success-'.uniqid('', true).'.ndjson.gz');
        $handle = gzopen($path, 'wb9');

        foreach ($rows as $row) {
            gzwrite($handle, json_encode($row).PHP_EOL);
        }

        gzclose($handle);

        return $path;
    }

    private function ordersDefinition(): SyncTableDefinition
    {
        return SyncTableDefinition::fromConfig('orders', [
            'tier' => 1,
            'sync_order' => 30,
            'strategy' => 'bigint_id+updated_at',
            'primary_key' => ['id'],
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
            'unique_indexes' => [['order_id'], ['cashfree_payment_id'], ['serial_number']],
            'soft_deletes' => true,
        ]);
    }
}
