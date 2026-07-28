<?php

namespace App\Services\Release;

/**
 * Reads/writes the deploy-time release snapshot (storage/app/private/release.json).
 *
 * @phpstan-type ReleaseManifest array{
 *     version: string|null,
 *     tag: string|null,
 *     build: string|null,
 *     deployed_at: string|null,
 *     release_date: string|null,
 * }
 */
class ReleaseManifestStore
{
    public function path(): string
    {
        $configured = config('app.release_manifest');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return storage_path('app/private/release.json');
    }

    /**
     * @return ReleaseManifest|null
     */
    public function read(): ?array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'version' => $this->nullableString($decoded['version'] ?? null),
            'tag' => $this->nullableString($decoded['tag'] ?? null),
            'build' => $this->nullableString($decoded['build'] ?? null),
            'deployed_at' => $this->nullableString($decoded['deployed_at'] ?? null),
            'release_date' => $this->nullableString($decoded['release_date'] ?? null),
        ];
    }

    /**
     * @param  ReleaseManifest  $manifest
     */
    public function write(array $manifest): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
