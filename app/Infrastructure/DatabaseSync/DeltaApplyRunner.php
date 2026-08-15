<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;

class DeltaApplyRunner
{
    public function __construct(
        private readonly TableDeltaApplier $applier,
        private readonly DatabaseSyncManifest $manifest,
    ) {}

    /**
     * @param  list<string>  $tableNames
     * @return array<string, mixed>
     */
    public function applyGeneration(string $generationId, array $tableNames): array
    {
        $inboxDirectory = rtrim((string) config('database-sync.inbox_directory', storage_path('app/private/db-sync/inbox')), '/');
        $generationDirectory = $inboxDirectory.'/'.$generationId;

        if (! is_dir($generationDirectory)) {
            throw new RuntimeException("Generation inbox not found [{$generationDirectory}].");
        }

        $manifestPath = $generationDirectory.'/'.$generationId.'.extract.json';

        if (! is_file($manifestPath)) {
            throw new RuntimeException("Extract manifest not found [{$manifestPath}].");
        }

        $extractManifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($extractManifest)) {
            throw new RuntimeException('Extract manifest is invalid JSON.');
        }

        $tables = $extractManifest['tables'] ?? [];

        if (! is_array($tables)) {
            throw new RuntimeException('Extract manifest tables section is invalid.');
        }

        $results = [];

        foreach ($this->manifest->tablesInSyncOrder() as $definition) {
            if ($tableNames !== [] && ! in_array($definition->name, $tableNames, true)) {
                continue;
            }

            $tableManifest = $tables[$definition->name] ?? null;

            if (! is_array($tableManifest)) {
                continue;
            }

            $chunks = $tableManifest['chunks'] ?? [];

            if (! is_array($chunks)) {
                throw new RuntimeException("Invalid chunk list for [{$definition->name}].");
            }

            $resolvedChunks = [];

            foreach ($chunks as $chunkPayload) {
                if (! is_array($chunkPayload)) {
                    throw new RuntimeException("Invalid chunk manifest for [{$definition->name}].");
                }

                $chunkPayload['file_path'] = $this->resolveChunkPath($generationDirectory, $chunkPayload);
                $resolvedChunks[] = ChunkManifest::fromArray($chunkPayload);
            }

            try {
                if ($definition->strategy === SyncCursorStrategy::FullReplace) {
                    $this->applier->applyFullReplace($definition, $resolvedChunks, $generationId);
                    $results[$definition->name] = [['chunk_seq' => 'all', 'status' => 'replaced']];

                    continue;
                }

                $tableResults = [];

                foreach ($resolvedChunks as $chunk) {
                    $this->applier->applyChunk($definition, $chunk, $generationId);
                    $tableResults[] = ['chunk_seq' => $chunk->chunkSeq, 'status' => 'applied'];
                }

                $results[$definition->name] = $tableResults;
            } catch (UniqueConflictException $exception) {
                $report = new ConflictReport($generationId, $definition->name, [$exception->conflict]);
                $this->writeConflictReport($generationId, $report);

                throw $exception;
            }
        }

        return [
            'generation_id' => $generationId,
            'tables' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $chunkPayload
     */
    private function resolveChunkPath(string $generationDirectory, array $chunkPayload): string
    {
        $filePath = $chunkPayload['file_path'] ?? null;

        if (is_string($filePath) && is_file($filePath)) {
            return $filePath;
        }

        $fileName = $chunkPayload['file_name'] ?? null;

        if (is_string($fileName)) {
            return $generationDirectory.'/'.$fileName;
        }

        $table = (string) ($chunkPayload['table'] ?? 'table');
        $chunkSeq = (int) ($chunkPayload['chunk_seq'] ?? 0);

        return $generationDirectory.'/'.$table.'.'.$chunkSeq.'.ndjson.gz';
    }

    private function writeConflictReport(string $generationId, ConflictReport $report): void
    {
        $directory = storage_path('app/private/db-sync/conflicts');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($directory.'/'.$generationId.'.json', $report->toJson().PHP_EOL, LOCK_EX);
    }
}
