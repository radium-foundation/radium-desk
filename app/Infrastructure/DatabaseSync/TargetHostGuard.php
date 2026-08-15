<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;

class TargetHostGuard
{
    public function assert(DatabaseSyncManifest $manifest, ?string $environment = null): void
    {
        $environment ??= app()->environment();

        if ($environment === 'testing' && config('database-sync.enforce_target_host') === false) {
            return;
        }

        if ($manifest->direction !== 'hostinger_to_vps') {
            throw new RuntimeException('Database sync direction cannot be reversed.');
        }

        if ($manifest->target->name !== 'vps') {
            throw new RuntimeException('remote_apply.php must run on the configured VPS target.');
        }

        if ($manifest->target->name === $manifest->source->name
            || $manifest->target->sshHost === $manifest->source->sshHost) {
            throw new RuntimeException('remote_apply.php cannot run against the Hostinger source.');
        }

        $expected = rtrim($manifest->target->projectPath, '/');
        $sourcePath = rtrim($manifest->source->projectPath, '/');
        $actual = rtrim(base_path(), '/');

        if ($actual === $sourcePath) {
            throw new RuntimeException('remote_apply.php cannot run against the Hostinger source.');
        }

        if ($expected !== $actual) {
            throw new RuntimeException('remote_apply.php must run on the configured VPS target (project_path mismatch).');
        }
    }
}
