<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\CheckpointAuthority;
use App\Infrastructure\DatabaseSync\ChunkManifest;
use App\Infrastructure\DatabaseSync\ConsistentSnapshotSession;
use App\Infrastructure\DatabaseSync\CutoverVerificationService;
use App\Infrastructure\DatabaseSync\CursorPredicateBuilder;
use App\Infrastructure\DatabaseSync\DatabaseSyncApplyService;
use App\Infrastructure\DatabaseSync\DatabaseSyncDryRunService;
use App\Infrastructure\DatabaseSync\DatabaseSyncManifest;
use App\Infrastructure\DatabaseSync\DeltaExtractRunner;
use App\Infrastructure\DatabaseSync\ExtractFileTransporter;
use App\Infrastructure\DatabaseSync\RemoteEndpointProfile;
use App\Infrastructure\DatabaseSync\RemoteTableProbe;
use App\Infrastructure\DatabaseSync\SchemaColumnParityGate;
use App\Infrastructure\DatabaseSync\SchemaIndexParityGate;
use App\Infrastructure\DatabaseSync\SchemaParityGate;
use App\Infrastructure\DatabaseSync\SchemaParityResult;
use App\Infrastructure\DatabaseSync\SyncTableDefinition;
use App\Infrastructure\DatabaseSync\SyncVerificationReport;
use App\Infrastructure\DatabaseSync\TableCheckpointStore;
use App\Infrastructure\DatabaseSync\TableDeltaApplier;
use App\Infrastructure\DatabaseSync\TableDeltaExtractor;
use App\Infrastructure\DatabaseSync\TargetHostGuard;
use App\Infrastructure\DatabaseSync\UniqueConflictChecker;
use App\Infrastructure\DatabaseSync\VpsDarkGate;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class Phase2SafetyFixTest extends TestCase
{
    use IsolatesTableCheckpointDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateTableCheckpointDirectory();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        $this->cleanupTableCheckpointDirectory();

        parent::tearDown();
    }

    public function test_mysql_snapshot_sql_sets_isolation_before_consistent_snapshot(): void
    {
        $sql = ConsistentSnapshotSession::beginSql('mysql');

        $this->assertSame([
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'START TRANSACTION WITH CONSISTENT SNAPSHOT',
        ], $sql);
        $this->assertNotSame('BEGIN', $sql[0] ?? null);
    }

    public function test_checkpoint_watermarks_merge_monotonically(): void
    {
        $directory = storage_path('framework/testing/checkpoints-mono-'.uniqid('', true));
        config(['database-sync.table_checkpoint_directory' => $directory]);

        try {
            $store = new TableCheckpointStore;
            $first = $this->chunkManifest('orders', 1, 50);
            $store->recordSuccessfulChunk('orders', 'gen-a', $first, [
                'last_id' => 50,
                'last_updated_at' => '2026-08-15 13:10:00',
                'last_id_at_watermark' => 50,
            ]);

            $second = $this->chunkManifest('orders', 2, 20);
            $store->recordSuccessfulChunk('orders', 'gen-a', $second, [
                'last_id' => 20,
                'last_updated_at' => '2026-08-15 12:00:00',
                'last_id_at_watermark' => 20,
            ]);

            $state = $store->read('orders');

            $this->assertSame(50, $state['last_id']);
            $this->assertSame('2026-08-15 13:10:00', $state['last_updated_at']);
            $this->assertSame(50, $state['last_id_at_watermark']);
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_extract_uses_vps_checkpoints_and_ignores_stale_hostinger_files(): void
    {
        $output = storage_path('framework/testing/extract-'.uniqid('', true));
        $checkpointDir = storage_path('framework/testing/hostinger-cp-'.uniqid('', true));
        config([
            'database-sync.table_checkpoint_directory' => $checkpointDir,
            'database-sync.chunk_row_limit' => 100,
        ]);

        Schema::create('lcds_orders_extract', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id');
            $table->timestamps();
        });

        try {
            DB::table('lcds_orders_extract')->insert([
                ['id' => 1, 'order_id' => 'A', 'created_at' => '2026-08-15 10:00:00', 'updated_at' => '2026-08-15 10:00:00'],
                ['id' => 6, 'order_id' => 'B', 'created_at' => '2026-08-15 11:00:00', 'updated_at' => '2026-08-15 11:00:00'],
            ]);

            $stale = new TableCheckpointStore;
            $stale->write('lcds_orders_extract', ['last_id' => 999999, 'last_updated_at' => '2099-01-01 00:00:00']);

            $definition = SyncTableDefinition::fromConfig('lcds_orders_extract', [
                'tier' => 1,
                'sync_order' => 30,
                'strategy' => 'bigint_id+updated_at',
                'primary_key' => ['id'],
                'updated_at' => 'updated_at',
                'created_at' => 'created_at',
            ]);

            $runner = new DeltaExtractRunner(new CursorPredicateBuilder);
            $result = $runner->extractGeneration(
                [$definition],
                'gen-vps',
                $output,
                ['lcds_orders_extract' => [
                    'last_id' => 5,
                    'last_updated_at' => '2026-08-15 12:00:00',
                    'last_id_at_watermark' => 5,
                ]],
            );

            $this->assertSame(1, $result['tables']['lcds_orders_extract']['chunks'][0]['row_count'] ?? null);
            $rows = $this->readChunkRows($result['tables']['lcds_orders_extract']['chunks'][0]['file_path']);
            $this->assertSame(6, $rows[0]['id'] ?? null);
        } finally {
            Schema::dropIfExists('lcds_orders_extract');
            foreach (glob($output.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($output)) {
                rmdir($output);
            }
            foreach (glob($checkpointDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($checkpointDir)) {
                rmdir($checkpointDir);
            }
        }
    }

    public function test_vps_dark_gate_fails_when_queue_work_is_active(): void
    {
        $probe = Mockery::mock(RemoteTableProbe::class);
        $probe->shouldReceive('fetchDarkStatus')
            ->once()
            ->with(Mockery::type(RemoteEndpointProfile::class))
            ->andReturn(['dark' => false, 'active' => ['queue:work'], 'process_list_ok' => true]);

        $gate = new VpsDarkGate($probe);
        $blockers = $gate->blockers(new DatabaseSyncManifest);

        $this->assertNotEmpty($blockers);
        $this->assertStringContainsString('queue:work', $blockers[0]);
    }

    public function test_missing_transport_refuses_apply(): void
    {
        $lockPath = storage_path('framework/testing/apply-lock-'.uniqid('', true));
        $checkpointPath = storage_path('framework/testing/dry-run-'.uniqid('', true).'.json');
        config([
            'database-sync.apply_lock_path' => $lockPath,
            'database-sync.checkpoint_path' => $checkpointPath,
        ]);

        $dryRun = Mockery::mock(DatabaseSyncDryRunService::class);
        $dryRun->shouldReceive('run')->once()->andReturn(new SyncVerificationReport(
            generatedAt: now()->toIso8601String(),
            direction: 'hostinger_to_vps',
            source: ['name' => 'hostinger'],
            target: ['name' => 'vps'],
            schemaParity: new SchemaParityResult(matched: true),
            tables: [],
        ));

        $schema = Mockery::mock(SchemaParityGate::class);
        $schema->shouldReceive('compare')->once()->andReturn(new SchemaParityResult(matched: true));

        $columns = Mockery::mock(SchemaColumnParityGate::class);
        $columns->shouldReceive('blockers')->once()->andReturn([]);

        $indexes = Mockery::mock(SchemaIndexParityGate::class);
        $indexes->shouldReceive('blockers')->once()->andReturn([]);

        $dark = Mockery::mock(VpsDarkGate::class);
        $dark->shouldReceive('blockers')->once()->andReturn([]);

        $authority = Mockery::mock(CheckpointAuthority::class);
        $authority->shouldReceive('pullFromTarget')->once()->andReturn(['orders' => ['last_id' => 10]]);

        $extractor = Mockery::mock(TableDeltaExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn([
            'generation_id' => 'gen-1',
            'tables' => ['orders' => ['chunks' => [['file_name' => 'orders.1.ndjson.gz']]]],
        ]);

        $transporter = Mockery::mock(ExtractFileTransporter::class);
        $transporter->shouldReceive('transfer')
            ->once()
            ->andThrow(new RuntimeException('Extract files were not transferred to the VPS inbox.'));

        $cutover = Mockery::mock(CutoverVerificationService::class);
        $cutover->shouldNotReceive('verifyAfterApply');

        $service = new DatabaseSyncApplyService(
            $dryRun,
            $schema,
            $columns,
            $indexes,
            $dark,
            new \App\Infrastructure\DatabaseSync\ApplyLock,
            $authority,
            $extractor,
            $transporter,
            $cutover,
        );

        try {
            $service->run('orders', 1, 'gen-1');
            $this->fail('Expected transport refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not transferred', $exception->getMessage());
        } finally {
            if (is_file($lockPath)) {
                unlink($lockPath);
            }
            if (is_file($checkpointPath)) {
                unlink($checkpointPath);
            }
        }
    }

    public function test_secondary_unique_insert_does_not_update_wrong_pk(): void
    {
        Schema::create('lcds_pk_only', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('payload');
            $table->timestamps();
        });

        try {
            DB::table('lcds_pk_only')->insert([
                'id' => 1,
                'order_id' => 'ORD-1',
                'payload' => 'original',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $definition = SyncTableDefinition::fromConfig('lcds_pk_only', [
                'tier' => 1,
                'sync_order' => 30,
                'strategy' => 'bigint_id+updated_at',
                'primary_key' => ['id'],
                'updated_at' => 'updated_at',
                'created_at' => 'created_at',
            ]);

            $path = $this->writeChunkFile([
                [
                    'id' => 2,
                    'order_id' => 'ORD-1',
                    'payload' => 'attacker',
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ]);

            $applier = new TableDeltaApplier(new UniqueConflictChecker, new TableCheckpointStore);

            try {
                $applier->applyChunk(
                    $definition,
                    new ChunkManifest('gen-pk', 'lcds_pk_only', 1, $path, hash_file('sha256', $path), 1),
                    'gen-pk',
                );
                $this->fail('Expected unique constraint failure.');
            } catch (QueryException) {
                // expected: PK-only insert cannot ODKU the other row
            }

            $row = DB::table('lcds_pk_only')->where('id', 1)->first();
            $this->assertSame('original', $row->payload);
            $this->assertSame(1, DB::table('lcds_pk_only')->count());
        } finally {
            Schema::dropIfExists('lcds_pk_only');
        }
    }

    public function test_target_host_guard_rejects_project_path_mismatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VPS target');

        (new TargetHostGuard)->assert(new DatabaseSyncManifest);
    }

    public function test_target_host_guard_allows_matching_vps_path(): void
    {
        $original = config('database-sync');
        $config = $original;
        $config['target']['project_path'] = base_path();
        config(['database-sync' => $config]);

        try {
            (new TargetHostGuard)->assert(new DatabaseSyncManifest);
            $this->assertTrue(true);
        } finally {
            config(['database-sync' => $original]);
        }
    }

    public function test_target_host_guard_cannot_be_disabled_by_config_outside_testing(): void
    {
        config(['database-sync.enforce_target_host' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VPS target');

        (new TargetHostGuard)->assert(new DatabaseSyncManifest, 'production');
    }

    public function test_vps_dark_gate_fails_closed_when_probe_unavailable(): void
    {
        $probe = Mockery::mock(RemoteTableProbe::class);
        $probe->shouldReceive('fetchDarkStatus')->once()->andThrow(new RuntimeException('ssh failed'));

        $gate = new VpsDarkGate($probe);
        $blockers = $gate->blockers(new DatabaseSyncManifest);

        $this->assertStringContainsString('unavailable', $blockers[0] ?? '');
    }

    public function test_composite_pk_same_timestamp_does_not_skip_or_repeat(): void
    {
        $output = storage_path('framework/testing/extract-cmp-'.uniqid('', true));
        Schema::create('lcds_composite', function (Blueprint $table): void {
            $table->unsignedBigInteger('left_id');
            $table->unsignedBigInteger('right_id');
            $table->string('label');
            $table->timestamp('created_at')->nullable();
            $table->primary(['left_id', 'right_id']);
        });

        try {
            DB::table('lcds_composite')->insert([
                ['left_id' => 1, 'right_id' => 1, 'label' => 'a', 'created_at' => '2026-08-15 10:00:00'],
                ['left_id' => 1, 'right_id' => 2, 'label' => 'b', 'created_at' => '2026-08-15 10:00:00'],
                ['left_id' => 2, 'right_id' => 1, 'label' => 'c', 'created_at' => '2026-08-15 10:00:00'],
            ]);

            $definition = SyncTableDefinition::fromConfig('lcds_composite', [
                'tier' => 1,
                'sync_order' => 20,
                'strategy' => 'composite_pk',
                'primary_key' => ['left_id', 'right_id'],
                'created_at' => 'created_at',
            ]);

            $runner = new DeltaExtractRunner(new CursorPredicateBuilder);
            $first = $runner->extractGeneration([$definition], 'gen-c1', $output, []);
            $this->assertSame(3, $first['tables']['lcds_composite']['chunks'][0]['row_count']);

            $checkpointDir = storage_path('framework/testing/cp-cmp-'.uniqid('', true));
            config(['database-sync.table_checkpoint_directory' => $checkpointDir]);
            $store = new TableCheckpointStore;
            $chunkPath = $first['tables']['lcds_composite']['chunks'][0]['file_path'];
            $applier = new TableDeltaApplier(new UniqueConflictChecker, $store);
            $applier->applyChunk(
                $definition,
                new ChunkManifest('gen-c1', 'lcds_composite', 1, $chunkPath, hash_file('sha256', $chunkPath), 3),
                'gen-c1',
            );

            $checkpoint = $store->read('lcds_composite');
            $this->assertSame(['left_id' => 2, 'right_id' => 1], $checkpoint['last_pk']);

            $second = $runner->extractGeneration([$definition], 'gen-c2', $output.'/2', ['lcds_composite' => $checkpoint]);
            $this->assertSame([], $second['tables']['lcds_composite']['chunks']);

            DB::table('lcds_composite')->insert([
                'left_id' => 2, 'right_id' => 2, 'label' => 'd', 'created_at' => '2026-08-15 10:00:00',
            ]);

            $third = $runner->extractGeneration([$definition], 'gen-c3', $output.'/3', ['lcds_composite' => $checkpoint]);
            $this->assertSame(1, $third['tables']['lcds_composite']['chunks'][0]['row_count'] ?? null);
            $rows = $this->readChunkRows($third['tables']['lcds_composite']['chunks'][0]['file_path']);
            $this->assertSame(2, $rows[0]['left_id']);
            $this->assertSame(2, $rows[0]['right_id']);
        } finally {
            Schema::dropIfExists('lcds_composite');
            foreach ([
                $output.'/3',
                $output.'/2',
                $output,
            ] as $dir) {
                foreach (glob($dir.'/*') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                if (is_dir($dir)) {
                    @rmdir($dir);
                }
            }
            if (isset($checkpointDir)) {
                foreach (glob($checkpointDir.'/*') ?: [] as $file) {
                    unlink($file);
                }
                if (is_dir($checkpointDir)) {
                    rmdir($checkpointDir);
                }
            }
        }
    }

    public function test_full_replace_removes_stale_rows_and_rolls_back_on_failure(): void
    {
        Schema::create('lcds_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
        });

        $checkpointDir = storage_path('framework/testing/cp-fr-'.uniqid('', true));
        config(['database-sync.table_checkpoint_directory' => $checkpointDir]);

        try {
            DB::table('lcds_roles')->insert([
                ['id' => 1, 'name' => 'keep', 'guard_name' => 'web'],
                ['id' => 99, 'name' => 'stale', 'guard_name' => 'web'],
            ]);

            $definition = SyncTableDefinition::fromConfig('lcds_roles', [
                'tier' => 1,
                'sync_order' => 10,
                'strategy' => 'full_replace',
                'primary_key' => ['id'],
                'unique_indexes' => [['name', 'guard_name']],
            ]);

            $path = $this->writeChunkFile([
                ['id' => 1, 'name' => 'keep', 'guard_name' => 'web'],
                ['id' => 2, 'name' => 'new', 'guard_name' => 'web'],
            ]);

            $applier = new TableDeltaApplier(new UniqueConflictChecker, new TableCheckpointStore);
            $applier->applyFullReplace(
                $definition,
                [new ChunkManifest('gen-fr', 'lcds_roles', 1, $path, hash_file('sha256', $path), 2)],
                'gen-fr',
            );

            $this->assertSame(2, DB::table('lcds_roles')->count());
            $this->assertNull(DB::table('lcds_roles')->where('id', 99)->first());
            $this->assertNotNull(DB::table('lcds_roles')->where('id', 2)->first());

            $badPath = $this->writeChunkFile([
                ['id' => 1, 'name' => 'keep', 'guard_name' => 'web'],
                ['id' => 3, 'name' => 'keep', 'guard_name' => 'web'],
            ]);

            try {
                $applier->applyFullReplace(
                    $definition,
                    [new ChunkManifest('gen-fr2', 'lcds_roles', 1, $badPath, hash_file('sha256', $badPath), 2)],
                    'gen-fr2',
                );
                $this->fail('Expected unique conflict or query failure.');
            } catch (\Throwable) {
                // expected
            }

            $this->assertSame(2, DB::table('lcds_roles')->count());
            $this->assertNotNull(DB::table('lcds_roles')->where('id', 2)->first());
            $this->assertNull(DB::table('lcds_roles')->where('id', 3)->first());
        } finally {
            Schema::dropIfExists('lcds_roles');
            foreach (glob($checkpointDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($checkpointDir)) {
                rmdir($checkpointDir);
            }
        }
    }

    public function test_snapshot_session_rolls_back_and_closes_on_extract_failure(): void
    {
        $session = new ConsistentSnapshotSession;
        $session->begin();
        $this->assertTrue($session->isOpen());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());

        $session->rollBack();
        $this->assertFalse($session->isOpen());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_mariadb_snapshot_sql_matches_mysql_order(): void
    {
        $this->assertSame(
            ConsistentSnapshotSession::beginSql('mysql'),
            ConsistentSnapshotSession::beginSql('mariadb'),
        );
    }

    public function test_checkpoint_authority_pulls_from_target_not_source(): void
    {
        $probe = Mockery::mock(RemoteTableProbe::class);
        $probe->shouldReceive('fetchCheckpoints')
            ->once()
            ->with(Mockery::on(static fn (RemoteEndpointProfile $profile): bool => $profile->name === 'vps'))
            ->andReturn(['orders' => ['last_id' => 42]]);

        $authority = new CheckpointAuthority($probe);
        $checkpoints = $authority->pullFromTarget(new DatabaseSyncManifest);

        $this->assertSame(42, $checkpoints['orders']['last_id']);
    }

    public function test_composite_pk_tuple_compare_is_numeric_for_integer_keys(): void
    {
        $builder = new CursorPredicateBuilder;
        $columns = ['left_id', 'right_id'];

        $this->assertSame(-1, $builder->comparePkTuples(['left_id' => 9, 'right_id' => 1], ['left_id' => 10, 'right_id' => 1], $columns));
        $this->assertSame(1, $builder->comparePkTuples(['left_id' => 10, 'right_id' => 1], ['left_id' => 9, 'right_id' => 1], $columns));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeChunkFile(array $rows): string
    {
        $path = storage_path('framework/testing/chunk-pk-'.uniqid('', true).'.ndjson.gz');
        $handle = gzopen($path, 'wb9');

        foreach ($rows as $row) {
            gzwrite($handle, json_encode($row).PHP_EOL);
        }

        gzclose($handle);

        return $path;
    }

    private function chunkManifest(string $table, int $seq, int $idMax): ChunkManifest
    {
        $path = storage_path('framework/testing/chunk-meta-'.uniqid('', true).'.ndjson.gz');
        file_put_contents($path, gzencode("{}\n"));

        return new ChunkManifest('gen-a', $table, $seq, $path, hash_file('sha256', $path), 1, 1, $idMax);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readChunkRows(string $path): array
    {
        $handle = gzopen($path, 'rb');
        $rows = [];

        while (($line = gzgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $rows[] = json_decode($trimmed, true);
            }
        }

        gzclose($handle);

        return $rows;
    }
}
