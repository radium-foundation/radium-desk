<?php

namespace App\Infrastructure\DatabaseSync;

use InvalidArgumentException;

final readonly class ChunkManifest
{
    /**
     * @param  list<string>  $primaryKey
     */
    public function __construct(
        public string $generationId,
        public string $table,
        public int $chunkSeq,
        public string $filePath,
        public string $sha256,
        public int $rowCount,
        public int|string|null $idMin = null,
        public int|string|null $idMax = null,
        public ?string $maxUpdatedAt = null,
        public ?string $snapshotAt = null,
        public array $primaryKey = ['id'],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $generationId = $payload['generation_id'] ?? null;
        $table = $payload['table'] ?? null;
        $filePath = $payload['file_path'] ?? null;
        $sha256 = $payload['sha256'] ?? null;

        if (! is_string($generationId) || $generationId === ''
            || ! is_string($table) || $table === ''
            || ! is_string($filePath) || $filePath === ''
            || ! is_string($sha256) || $sha256 === '') {
            throw new InvalidArgumentException('Chunk manifest is missing required fields.');
        }

        $primaryKey = $payload['primary_key'] ?? ['id'];

        if (! is_array($primaryKey)) {
            $primaryKey = ['id'];
        }

        return new self(
            generationId: $generationId,
            table: $table,
            chunkSeq: (int) ($payload['chunk_seq'] ?? 0),
            filePath: $filePath,
            sha256: $sha256,
            rowCount: (int) ($payload['row_count'] ?? 0),
            idMin: $payload['id_min'] ?? null,
            idMax: $payload['id_max'] ?? null,
            maxUpdatedAt: is_string($payload['max_updated_at'] ?? null) ? $payload['max_updated_at'] : null,
            snapshotAt: is_string($payload['snapshot_at'] ?? null) ? $payload['snapshot_at'] : null,
            primaryKey: array_values(array_map(strval(...), $primaryKey)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation_id' => $this->generationId,
            'table' => $this->table,
            'chunk_seq' => $this->chunkSeq,
            'file_path' => $this->filePath,
            'sha256' => $this->sha256,
            'row_count' => $this->rowCount,
            'id_min' => $this->idMin,
            'id_max' => $this->idMax,
            'max_updated_at' => $this->maxUpdatedAt,
            'snapshot_at' => $this->snapshotAt,
            'primary_key' => $this->primaryKey,
        ];
    }

    public function verifyChecksum(): void
    {
        if (! is_file($this->filePath)) {
            throw new InvalidArgumentException("Chunk file not found [{$this->filePath}].");
        }

        $actual = hash_file('sha256', $this->filePath);

        if ($actual === false || ! hash_equals($this->sha256, $actual)) {
            throw new InvalidArgumentException("Chunk checksum mismatch for [{$this->table}] chunk {$this->chunkSeq}.");
        }
    }
}
