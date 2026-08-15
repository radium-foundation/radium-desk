<?php

namespace App\Infrastructure\DatabaseSync;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class TableDeltaApplier
{
    public function __construct(
        private readonly UniqueConflictChecker $conflictChecker,
        private readonly TableCheckpointStore $checkpointStore,
    ) {}

    public function applyChunk(
        SyncTableDefinition $table,
        ChunkManifest $chunk,
        string $generationId,
    ): void {
        $chunk->verifyChecksum();

        $rows = $this->readChunkRows($chunk->filePath);

        if (count($rows) !== $chunk->rowCount) {
            throw new RuntimeException("Chunk row count mismatch for [{$table->name}] chunk {$chunk->chunkSeq}.");
        }

        DB::beginTransaction();

        try {
            $this->ensureForeignKeyChecksEnabled();

            if ($table->strategy === SyncCursorStrategy::FullReplace) {
                throw new RuntimeException("Table [{$table->name}] uses full_replace and must be applied as a complete table, not a single chunk.");
            }

            foreach ($rows as $row) {
                if ($table->strategy === SyncCursorStrategy::StringPk && $table->name === 'reference_sequences') {
                    $this->mergeReferenceSequence($row);

                    continue;
                }

                $conflict = $this->conflictChecker->detectConflict($table, $row);

                if ($conflict !== null) {
                    throw new UniqueConflictException($conflict);
                }

                $this->pkUpsert($table, $row);
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $this->checkpointStore->recordSuccessfulChunk(
            $table->name,
            $generationId,
            $chunk,
            $this->checkpointMetadata($table, $rows),
        );
    }

    /**
     * FK-safe replace: delete target rows whose PK is absent from the extract, then PK insert/update.
     *
     * @param  list<ChunkManifest>  $chunks
     */
    public function applyFullReplace(SyncTableDefinition $table, array $chunks, string $generationId): void
    {
        if ($chunks === []) {
            return;
        }

        $allRows = [];

        foreach ($chunks as $chunk) {
            $chunk->verifyChecksum();
            $rows = $this->readChunkRows($chunk->filePath);

            if (count($rows) !== $chunk->rowCount) {
                throw new RuntimeException("Chunk row count mismatch for [{$table->name}] chunk {$chunk->chunkSeq}.");
            }

            foreach ($rows as $row) {
                $allRows[] = $row;
            }
        }

        DB::beginTransaction();

        try {
            $this->ensureForeignKeyChecksEnabled();
            $this->deleteStaleRows($table, $allRows);

            foreach ($allRows as $row) {
                $conflict = $this->conflictChecker->detectConflict($table, $row);

                if ($conflict !== null) {
                    throw new UniqueConflictException($conflict);
                }

                $this->pkUpsert($table, $row);
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $lastChunk = $chunks[array_key_last($chunks)];
        $this->checkpointStore->recordSuccessfulChunk(
            $table->name,
            $generationId,
            $lastChunk,
            $this->checkpointMetadata($table, $allRows),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readChunkRows(string $path): array
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open chunk file [{$path}].");
        }

        $rows = [];

        try {
            while (($line = gzgets($handle)) !== false) {
                $trimmed = trim($line);

                if ($trimmed === '') {
                    continue;
                }

                $decoded = json_decode($trimmed, true);

                if (! is_array($decoded)) {
                    throw new RuntimeException("Invalid NDJSON row in chunk [{$path}].");
                }

                $rows[] = $decoded;
            }
        } finally {
            gzclose($handle);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function pkUpsert(SyncTableDefinition $table, array $row): void
    {
        $query = DB::table($table->name);

        foreach ($table->primaryKey as $column) {
            $query->where($column, $row[$column] ?? null);
        }

        $existing = $query->first();

        if ($existing === null) {
            DB::table($table->name)->insert($row);

            return;
        }

        $updates = $row;

        foreach ($table->primaryKey as $column) {
            unset($updates[$column]);
        }

        if ($updates === []) {
            return;
        }

        $updateQuery = DB::table($table->name);

        foreach ($table->primaryKey as $column) {
            $updateQuery->where($column, $row[$column] ?? null);
        }

        $updateQuery->update($updates);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mergeReferenceSequence(array $row): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'INSERT INTO reference_sequences (name, current_value, updated_at, created_at)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(name) DO UPDATE SET
                    current_value = MAX(reference_sequences.current_value, excluded.current_value),
                    updated_at = MAX(reference_sequences.updated_at, excluded.updated_at)',
                [
                    $row['name'],
                    $row['current_value'],
                    $row['updated_at'],
                    $row['created_at'],
                ],
            );

            return;
        }

        DB::statement(
            'INSERT INTO reference_sequences (name, current_value, updated_at, created_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                current_value = GREATEST(reference_sequences.current_value, VALUES(current_value)),
                updated_at = GREATEST(reference_sequences.updated_at, VALUES(updated_at))',
            [
                $row['name'],
                $row['current_value'],
                $row['updated_at'],
                $row['created_at'],
            ],
        );
    }

    private function ensureForeignKeyChecksEnabled(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');

            return;
        }

        DB::statement('SET foreign_key_checks = 1');
    }

    /**
     * @param  list<array<string, mixed>>  $incomingRows
     */
    private function deleteStaleRows(SyncTableDefinition $table, array $incomingRows): void
    {
        $incomingKeys = [];

        foreach ($incomingRows as $row) {
            $incomingKeys[$this->primaryKeyFingerprint($table, $row)] = true;
        }

        foreach (DB::table($table->name)->select($table->primaryKey)->cursor() as $current) {
            $currentRow = (array) $current;

            if (isset($incomingKeys[$this->primaryKeyFingerprint($table, $currentRow)])) {
                continue;
            }

            $delete = DB::table($table->name);

            foreach ($table->primaryKey as $column) {
                $delete->where($column, $currentRow[$column] ?? null);
            }

            $delete->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function primaryKeyFingerprint(SyncTableDefinition $table, array $row): string
    {
        $parts = [];

        foreach ($table->primaryKey as $column) {
            $parts[] = (string) ($row[$column] ?? '');
        }

        return implode("\x1f", $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function checkpointMetadata(SyncTableDefinition $table, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $metadata = [];

        if ($table->strategy->supportsMaxPrimaryKey() && count($table->primaryKey) === 1) {
            $pkColumn = $table->primaryKey[0];
            $numericIds = [];

            foreach ($rows as $row) {
                if (is_numeric($row[$pkColumn] ?? null)) {
                    $numericIds[] = (int) $row[$pkColumn];
                }
            }

            if ($numericIds !== []) {
                $metadata['last_id'] = max($numericIds);
            }
        }

        if ($table->strategy->supportsMaxUpdatedAt() && $table->updatedAtColumn !== null) {
            $updatedAtColumn = $table->updatedAtColumn;
            $maxUpdatedAt = null;
            $lastIdAtWatermark = 0;

            foreach ($rows as $row) {
                $candidate = (string) ($row[$updatedAtColumn] ?? '');

                if ($maxUpdatedAt === null || $candidate > $maxUpdatedAt) {
                    $maxUpdatedAt = $candidate;
                    $lastIdAtWatermark = (int) ($row['id'] ?? 0);
                } elseif ($candidate === $maxUpdatedAt) {
                    $lastIdAtWatermark = max($lastIdAtWatermark, (int) ($row['id'] ?? 0));
                }
            }

            $metadata['last_updated_at'] = $maxUpdatedAt;
            $metadata['last_id_at_watermark'] = $lastIdAtWatermark;
        }

        if (in_array($table->strategy, [SyncCursorStrategy::CompositePk, SyncCursorStrategy::CreatedAtPk], true)) {
            $lastRow = $rows[array_key_last($rows)];
            $lastPk = [];

            foreach ($table->primaryKey as $column) {
                $lastPk[$column] = $lastRow[$column] ?? null;
            }

            $metadata['last_pk'] = $lastPk;

            if ($table->createdAtColumn !== null && isset($lastRow[$table->createdAtColumn])) {
                $metadata['last_created_at'] = (string) $lastRow[$table->createdAtColumn];
            }
        }

        return $metadata;
    }
}
