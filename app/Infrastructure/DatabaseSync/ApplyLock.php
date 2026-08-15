<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;

class ApplyLock
{
    public function path(): string
    {
        $configured = config('database-sync.apply_lock_path');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return storage_path('app/private/db-sync/.apply.lock');
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): ?array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function acquire(string $generationId): void
    {
        if ($this->isLocked()) {
            throw new RuntimeException('Database sync apply lock is already held.');
        }

        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException('Database sync apply lock is already held.');
        }

        $payload = json_encode([
            'generation_id' => $generationId,
            'pid' => getmypid(),
            'started_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            fclose($handle);
            @unlink($path);

            throw new RuntimeException('Unable to encode apply lock payload.');
        }

        fwrite($handle, $payload.PHP_EOL);
        fclose($handle);
    }

    public function release(): void
    {
        $path = $this->path();

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function isLocked(): bool
    {
        return is_file($this->path());
    }
}
