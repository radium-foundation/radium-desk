<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;

class VpsDarkGate
{
    public function __construct(
        private readonly RemoteTableProbe $remoteTableProbe,
    ) {}

    /**
     * Process-list inspection cannot prove DNS or cron configuration.
     * Those remain operational prerequisites confirmed by --vps-is-dark.
     *
     * @return list<string>
     */
    public function blockers(?DatabaseSyncManifest $manifest = null): array
    {
        $manifest ??= new DatabaseSyncManifest;

        if (! config('database-sync.require_vps_dark', true)) {
            if (! app()->environment('testing')) {
                return ['VPS dark-status cannot be disabled outside testing.'];
            }

            return [];
        }

        try {
            $status = $this->remoteTableProbe->fetchDarkStatus($manifest->target);
        } catch (\Throwable $exception) {
            return ['VPS dark-status probe unavailable: '.$exception->getMessage()];
        }

        if (! array_key_exists('dark', $status) || ! isset($status['active']) || ! is_array($status['active'])) {
            return ['VPS dark-status probe returned an ambiguous response.'];
        }

        if (($status['process_list_ok'] ?? false) !== true) {
            return ['VPS dark-status probe could not enumerate processes; failing closed.'];
        }

        $blockers = [];

        foreach ($status['active'] as $command) {
            if (is_string($command) && $command !== '') {
                $blockers[] = "Forbidden VPS command appears active: {$command}";
            }
        }

        return $blockers;
    }

    public function assertDark(?DatabaseSyncManifest $manifest = null): void
    {
        $blockers = $this->blockers($manifest);

        if ($blockers !== []) {
            throw new RuntimeException(implode(' ', $blockers));
        }
    }
}
