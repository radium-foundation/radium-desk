<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;
use Symfony\Component\Process\Process;

class TableDeltaExtractor
{
    private const EXTRACT_SCRIPT = 'app/Infrastructure/DatabaseSync/Scripts/remote_extract.php';

    /**
     * @param  array<string, array<string, mixed>>  $checkpointsByTable
     * @return array<string, mixed>
     */
    public function extract(
        DatabaseSyncManifest $manifest,
        string $generationId,
        ?string $table = null,
        ?int $tier = null,
        array $checkpointsByTable = [],
    ): array {
        if ($manifest->direction !== 'hostinger_to_vps') {
            throw new RuntimeException('Database sync direction cannot be reversed.');
        }

        $tables = $manifest->filterTables($table, $tier);
        $remoteCheckpointsPath = $this->stageCheckpointsOnSource($manifest->source, $generationId, $checkpointsByTable);

        $arguments = [
            '--generation-id='.$generationId,
            '--tables='.implode(',', array_map(static fn (SyncTableDefinition $definition): string => $definition->name, $tables)),
            '--checkpoints-file='.$remoteCheckpointsPath,
        ];

        return $this->executeRemoteScript($manifest->source, self::EXTRACT_SCRIPT, $arguments);
    }

    /**
     * @param  array<string, array<string, mixed>>  $checkpointsByTable
     */
    private function stageCheckpointsOnSource(RemoteEndpointProfile $source, string $generationId, array $checkpointsByTable): string
    {
        $remoteDirectory = rtrim($source->projectPath, '/').'/storage/app/private/db-sync/inbox/'.$generationId;
        $remotePath = $remoteDirectory.'/vps-checkpoints.json';
        $localPath = storage_path('app/private/db-sync/staging/'.$generationId.'-vps-checkpoints.json');
        $localDirectory = dirname($localPath);

        if (! is_dir($localDirectory)) {
            mkdir($localDirectory, 0755, true);
        }

        $encoded = json_encode(['authority' => 'vps', 'checkpoints' => $checkpointsByTable], JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('Unable to encode VPS checkpoints for extract.');
        }

        if (file_put_contents($localPath, $encoded.PHP_EOL) === false) {
            throw new RuntimeException('Unable to write local VPS checkpoint staging file.');
        }

        $this->run(sprintf(
            'ssh -p %d -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s %s',
            $source->sshPort,
            escapeshellarg($source->sshTarget()),
            escapeshellarg('mkdir -p '.escapeshellarg($remoteDirectory)),
        ));

        $this->run(sprintf(
            'scp -P %d -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s %s',
            $source->sshPort,
            escapeshellarg($localPath),
            escapeshellarg($source->sshTarget().':'.$remotePath),
        ));

        return $remotePath;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function executeRemoteScript(RemoteEndpointProfile $profile, string $script, array $arguments): array
    {
        $remoteScript = rtrim($profile->projectPath, '/').'/'.$script;
        $remoteCommand = sprintf(
            'cd %s && %s %s %s',
            escapeshellarg($profile->projectPath),
            escapeshellarg($profile->phpBin),
            escapeshellarg($remoteScript),
            implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $arguments)),
        );

        $output = $this->run(sprintf(
            'ssh -p %d -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s %s',
            $profile->sshPort,
            escapeshellarg($profile->sshTarget()),
            escapeshellarg($remoteCommand),
        ));

        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Remote extract returned invalid JSON.');
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            throw new RuntimeException($decoded['error']);
        }

        return $decoded;
    }

    private function run(string $command): string
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Remote extract command failed.'));
        }

        return $process->getOutput();
    }
}
