<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;

class CheckpointAuthority
{
    public function __construct(
        private readonly RemoteTableProbe $remoteTableProbe,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function pullFromTarget(DatabaseSyncManifest $manifest): array
    {
        if ($manifest->direction !== 'hostinger_to_vps') {
            throw new RuntimeException('Database sync direction cannot be reversed.');
        }

        return $this->remoteTableProbe->fetchCheckpoints($manifest->target);
    }
}
