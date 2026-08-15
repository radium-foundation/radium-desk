<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\ChunkManifest;
use InvalidArgumentException;
use Tests\TestCase;

class ChunkManifestTest extends TestCase
{
    public function test_corrupt_chunk_checksum_is_rejected(): void
    {
        $path = storage_path('framework/testing/chunk-'.uniqid('', true).'.ndjson.gz');
        file_put_contents($path, gzencode("{\"id\":1}\n"));

        try {
            $manifest = new ChunkManifest(
                generationId: 'gen-1',
                table: 'orders',
                chunkSeq: 1,
                filePath: $path,
                sha256: str_repeat('a', 64),
                rowCount: 1,
            );

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('checksum mismatch');

            $manifest->verifyChecksum();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
