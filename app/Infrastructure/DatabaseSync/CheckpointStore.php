<?php

namespace App\Infrastructure\DatabaseSync;

class CheckpointStore
{
    public function path(): string
    {
        $configured = config('database-sync.checkpoint_path');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return storage_path('app/private/db-sync/state.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return $this->defaultState();
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return $this->defaultState();
        }

        return array_merge($this->defaultState(), $decoded);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function write(array $state): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $payload = json_encode(
            array_merge($this->defaultState(), $state),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if ($payload === false) {
            throw new \RuntimeException('Unable to encode database sync checkpoint state.');
        }

        $temporaryPath = $path.'.tmp';

        if (file_put_contents($temporaryPath, $payload.PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write temporary checkpoint file [{$temporaryPath}].");
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException("Unable to atomically replace checkpoint file [{$path}].");
        }
    }

    /**
     * @param  array<string, mixed>  $dryRunMetadata
     */
    public function recordDryRun(array $dryRunMetadata): void
    {
        $state = $this->read();
        $state['last_dry_run_at'] = now()->toIso8601String();
        $state['last_dry_run'] = $dryRunMetadata;
        $this->write($state);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultState(): array
    {
        return [
            'version' => 1,
            'direction' => 'hostinger_to_vps',
            'last_dry_run_at' => null,
            'last_dry_run' => null,
            'tables' => [],
        ];
    }
}
