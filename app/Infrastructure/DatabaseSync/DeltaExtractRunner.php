<?php

namespace App\Infrastructure\DatabaseSync;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeltaExtractRunner
{
    public function __construct(
        private readonly CursorPredicateBuilder $predicateBuilder,
        private readonly ConsistentSnapshotSession $snapshotSession = new ConsistentSnapshotSession(),
    ) {}

    /**
     * Extract all selected tables inside one consistent snapshot.
     * Checkpoints must come from the VPS authority — never from Hostinger-local files.
     *
     * @param  list<SyncTableDefinition>  $tables
     * @param  array<string, array<string, mixed>>  $checkpointsByTable
     * @return array<string, mixed>
     */
    public function extractGeneration(
        array $tables,
        string $generationId,
        string $outputDirectory,
        array $checkpointsByTable,
    ): array {
        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException("Unable to create extract output directory [{$outputDirectory}].");
        }

        $snapshotAt = now()->toIso8601String();
        $chunkLimit = (int) config('database-sync.chunk_row_limit', 2000);
        $results = [];

        $this->snapshotSession->begin();

        try {
            foreach ($tables as $table) {
                $checkpoint = $checkpointsByTable[$table->name] ?? (new TableCheckpointStore)->defaultTableState($table->name);
                $results[$table->name] = $this->extractTableFromOpenSnapshot(
                    $table,
                    $generationId,
                    $outputDirectory,
                    $checkpoint,
                    $snapshotAt,
                    $chunkLimit,
                );
            }

            $this->snapshotSession->commit();
        } catch (\Throwable $exception) {
            $this->snapshotSession->rollBack();

            throw $exception;
        }

        return [
            'generation_id' => $generationId,
            'snapshot_at' => $snapshotAt,
            'tables' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    private function extractTableFromOpenSnapshot(
        SyncTableDefinition $table,
        string $generationId,
        string $outputDirectory,
        array $checkpoint,
        string $snapshotAt,
        int $chunkLimit,
    ): array {
        $query = DB::table($table->name);
        $this->applyCursor($table, $query, $checkpoint);

        foreach ($table->primaryKey as $column) {
            $query->orderBy($column);
        }

        if ($table->strategy === SyncCursorStrategy::CreatedAtPk && $table->createdAtColumn !== null) {
            $query->reorder();
            $query->orderBy($table->createdAtColumn);
            foreach ($table->primaryKey as $column) {
                $query->orderBy($column);
            }
        }

        $chunks = [];
        $chunkSeq = 0;
        $buffer = [];

        foreach ($query->cursor() as $row) {
            $buffer[] = (array) $row;

            if (count($buffer) >= $chunkLimit) {
                $chunks[] = $this->writeChunk($table, $generationId, $outputDirectory, ++$chunkSeq, $buffer, $snapshotAt);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $chunks[] = $this->writeChunk($table, $generationId, $outputDirectory, ++$chunkSeq, $buffer, $snapshotAt);
        }

        return [
            'table' => $table->name,
            'generation_id' => $generationId,
            'snapshot_at' => $snapshotAt,
            'chunks' => $chunks,
        ];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $checkpoint
     */
    private function applyCursor(SyncTableDefinition $table, $query, array $checkpoint): void
    {
        match ($table->strategy) {
            SyncCursorStrategy::BigintIdUpdatedAt,
            SyncCursorStrategy::UuidPk => $this->applyMutableCursor($table, $query, $checkpoint),
            SyncCursorStrategy::BigintIdInsertOnly => $this->applyAppendOnlyCursor($query, $checkpoint),
            SyncCursorStrategy::StringPk,
            SyncCursorStrategy::FullReplace => null,
            SyncCursorStrategy::CompositePk => $this->applyCompositeCursor($table, $query, $checkpoint),
            SyncCursorStrategy::CreatedAtPk => $this->applyCreatedAtPkCursor($table, $query, $checkpoint),
        };
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $checkpoint
     */
    private function applyMutableCursor(SyncTableDefinition $table, $query, array $checkpoint): void
    {
        $predicate = $this->predicateBuilder->buildMutablePredicate($table, $checkpoint);
        $query->whereRaw($predicate['sql'], $predicate['bindings']);
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $checkpoint
     */
    private function applyCompositeCursor(SyncTableDefinition $table, $query, array $checkpoint): void
    {
        $predicate = $this->predicateBuilder->buildCompositePkPredicate($table, $checkpoint);
        $query->whereRaw($predicate['sql'], $predicate['bindings']);
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $checkpoint
     */
    private function applyCreatedAtPkCursor(SyncTableDefinition $table, $query, array $checkpoint): void
    {
        $predicate = $this->predicateBuilder->buildCreatedAtPkPredicate($table, $checkpoint);
        $query->whereRaw($predicate['sql'], $predicate['bindings']);
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $checkpoint
     */
    private function applyAppendOnlyCursor($query, array $checkpoint): void
    {
        $predicate = $this->predicateBuilder->buildAppendOnlyPredicate($checkpoint);
        $query->whereRaw($predicate['sql'], $predicate['bindings']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function writeChunk(
        SyncTableDefinition $table,
        string $generationId,
        string $outputDirectory,
        int $chunkSeq,
        array $rows,
        string $snapshotAt,
    ): array {
        $fileName = "{$table->name}.{$chunkSeq}.ndjson.gz";
        $filePath = rtrim($outputDirectory, '/').'/'.$fileName;
        $handle = gzopen($filePath, 'wb9');

        if ($handle === false) {
            throw new RuntimeException("Unable to write chunk file [{$filePath}].");
        }

        try {
            foreach ($rows as $row) {
                $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($encoded === false) {
                    throw new RuntimeException('Unable to encode extract row.');
                }

                gzwrite($handle, $encoded.PHP_EOL);
            }
        } finally {
            gzclose($handle);
        }

        $sha256 = hash_file('sha256', $filePath);

        if ($sha256 === false) {
            throw new RuntimeException("Unable to hash chunk file [{$filePath}].");
        }

        $idColumn = $table->primaryKey[0];
        $ids = array_map(static fn (array $row): int|string => $row[$idColumn] ?? 0, $rows);
        $maxUpdatedAt = null;

        if ($table->updatedAtColumn !== null) {
            $maxUpdatedAt = max(array_map(
                static fn (array $row): string => (string) ($row[$table->updatedAtColumn] ?? ''),
                $rows,
            ));
        }

        $payload = (new ChunkManifest(
            generationId: $generationId,
            table: $table->name,
            chunkSeq: $chunkSeq,
            filePath: $filePath,
            sha256: $sha256,
            rowCount: count($rows),
            idMin: min($ids),
            idMax: max($ids),
            maxUpdatedAt: $maxUpdatedAt,
            snapshotAt: $snapshotAt,
            primaryKey: $table->primaryKey,
        ))->toArray();

        $payload['file_name'] = $fileName;

        return $payload;
    }
}
