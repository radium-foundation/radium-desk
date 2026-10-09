<?php

namespace App\CentralWallet\Reliability;

use RuntimeException;

final class CentralWalletRuntimeManifestStore
{
    public function __construct(
        private readonly ?string $path = null,
    ) {}

    public function path(): string
    {
        return $this->path ?? storage_path('app/private/runtime-manifest.json');
    }

    /**
     * @return array<string, mixed>|null
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

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function write(array $manifest): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create runtime manifest directory: {$directory}");
        }

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode runtime manifest JSON.');
        }

        if (file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException("Unable to write runtime manifest: {$path}");
        }
    }

    public function sha256(): ?string
    {
        $path = $this->path();

        return is_file($path) ? hash_file('sha256', $path) : null;
    }
}
