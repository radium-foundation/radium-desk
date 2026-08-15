<?php

namespace App\Infrastructure\DatabaseSync;

class TableCheckpointStore
{
    public function directory(): string
    {
        $configured = config('database-sync.table_checkpoint_directory');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return storage_path('app/private/db-sync/checkpoints');
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $table): array
    {
        $path = $this->tablePath($table);

        if (! is_file($path)) {
            return $this->defaultTableState($table);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return $this->defaultTableState($table);
        }

        return array_merge($this->defaultTableState($table), $decoded);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function write(string $table, array $state): void
    {
        $path = $this->tablePath($table);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $payload = json_encode(
            array_merge($this->defaultTableState($table), $state),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if ($payload === false) {
            throw new \RuntimeException("Unable to encode checkpoint for [{$table}].");
        }

        $temporaryPath = $path.'.tmp';

        if (file_put_contents($temporaryPath, $payload.PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write temporary checkpoint [{$temporaryPath}].");
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException("Unable to atomically replace checkpoint [{$path}].");
        }
    }

    /**
     * @param  array<string, mixed>  $chunkMetadata
     */
    public function recordSuccessfulChunk(string $table, string $generationId, ChunkManifest $chunk, array $chunkMetadata = []): void
    {
        $state = $this->read($table);
        $incomingId = $chunkMetadata['last_id'] ?? $chunk->idMax ?? null;
        $incomingUpdatedAt = $chunkMetadata['last_updated_at'] ?? $chunk->maxUpdatedAt ?? null;
        $incomingIdAtWatermark = $chunkMetadata['last_id_at_watermark'] ?? null;

        $state['last_generation_id'] = $generationId;
        $state['last_chunk_checksum'] = $chunk->sha256;
        $state['last_chunk_seq'] = $chunk->chunkSeq;
        $state['last_id'] = $this->greatestNumericId($state['last_id'], $incomingId);
        [$state['last_updated_at'], $state['last_id_at_watermark']] = $this->mergeWatermark(
            $state['last_updated_at'] ?? null,
            (int) ($state['last_id_at_watermark'] ?? 0),
            is_string($incomingUpdatedAt) ? $incomingUpdatedAt : null,
            $incomingIdAtWatermark,
        );

        $incomingCreatedAt = $chunkMetadata['last_created_at'] ?? null;
        if (is_string($incomingCreatedAt) && ($state['last_created_at'] === null || $incomingCreatedAt > $state['last_created_at'])) {
            $state['last_created_at'] = $incomingCreatedAt;
        }

        if (isset($chunkMetadata['last_pk']) && is_array($chunkMetadata['last_pk'])) {
            $columns = array_keys($chunkMetadata['last_pk']);
            $currentPk = is_array($state['last_pk'] ?? null) ? $state['last_pk'] : null;
            if ((new CursorPredicateBuilder)->comparePkTuples($currentPk, $chunkMetadata['last_pk'], $columns) < 0) {
                $state['last_pk'] = $chunkMetadata['last_pk'];
            } elseif ($currentPk === null) {
                $state['last_pk'] = $chunkMetadata['last_pk'];
            }
        }

        $state['applied_at'] = now()->toIso8601String();
        $state['chunk_bounds'] = [
            'id_min' => $chunk->idMin,
            'id_max' => $chunk->idMax,
        ];

        $this->write($table, $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportAll(): array
    {
        $exported = [];
        $directory = $this->directory();

        if (! is_dir($directory)) {
            return $exported;
        }

        foreach (glob($directory.'/*.json') ?: [] as $path) {
            $table = basename($path, '.json');

            if ($table === '' || str_ends_with($path, '.tmp')) {
                continue;
            }

            $exported[$table] = $this->read($table);
        }

        return $exported;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultTableState(string $table): array
    {
        return [
            'table' => $table,
            'last_id' => 0,
            'last_updated_at' => null,
            'last_id_at_watermark' => 0,
            'last_created_at' => null,
            'last_pk' => null,
            'last_generation_id' => null,
            'last_chunk_checksum' => null,
            'last_chunk_seq' => null,
            'applied_at' => null,
            'chunk_bounds' => null,
        ];
    }

    private function tablePath(string $table): string
    {
        return rtrim($this->directory(), '/').'/'.$table.'.json';
    }

    private function greatestNumericId(mixed $current, mixed $incoming): int|string
    {
        if (! is_numeric($incoming)) {
            return is_numeric($current) ? (int) $current : ($current ?? 0);
        }

        if (! is_numeric($current)) {
            return (int) $incoming;
        }

        return max((int) $current, (int) $incoming);
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    private function mergeWatermark(mixed $currentUpdatedAt, int $currentIdAtWatermark, ?string $incomingUpdatedAt, mixed $incomingIdAtWatermark): array
    {
        if ($incomingUpdatedAt === null || $incomingUpdatedAt === '') {
            return [is_string($currentUpdatedAt) ? $currentUpdatedAt : null, $currentIdAtWatermark];
        }

        if (! is_string($currentUpdatedAt) || $currentUpdatedAt === '' || $incomingUpdatedAt > $currentUpdatedAt) {
            return [$incomingUpdatedAt, is_numeric($incomingIdAtWatermark) ? (int) $incomingIdAtWatermark : 0];
        }

        if ($incomingUpdatedAt === $currentUpdatedAt) {
            $incomingId = is_numeric($incomingIdAtWatermark) ? (int) $incomingIdAtWatermark : 0;

            return [$currentUpdatedAt, max($currentIdAtWatermark, $incomingId)];
        }

        return [$currentUpdatedAt, $currentIdAtWatermark];
    }
}
