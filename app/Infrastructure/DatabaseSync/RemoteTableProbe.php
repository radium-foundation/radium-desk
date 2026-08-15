<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;
use Symfony\Component\Process\Process;

class RemoteTableProbe
{
    private const INSPECT_SCRIPT = 'app/Infrastructure/DatabaseSync/Scripts/remote_inspect.php';

    public function probeTable(RemoteEndpointProfile $profile, SyncTableDefinition $table): TableProbeResult
    {
        $arguments = [
            '--action=table-stats',
            '--table='.$table->name,
            '--strategy='.$table->strategy->value,
            '--primary-key='.implode(',', $table->primaryKey),
        ];

        if ($table->updatedAtColumn !== null) {
            $arguments[] = '--updated-at='.$table->updatedAtColumn;
        }

        if ($table->createdAtColumn !== null) {
            $arguments[] = '--created-at='.$table->createdAtColumn;
        }

        $payload = $this->executeRemoteInspect($profile, $arguments);

        return TableProbeResult::fromPayload($profile->name, $table->name, $payload);
    }

    /**
     * @return array<string, int>
     */
    public function fetchMigrationStatus(RemoteEndpointProfile $profile): array
    {
        $payload = $this->executeRemoteInspect($profile, ['--action=migration-status']);

        if (isset($payload['error']) && is_string($payload['error'])) {
            throw new RuntimeException($payload['error']);
        }

        $migrations = $payload['migrations'] ?? null;

        if (! is_array($migrations)) {
            throw new RuntimeException('Remote migration status response was invalid.');
        }

        $normalized = [];

        foreach ($migrations as $migration => $batch) {
            if (! is_string($migration)) {
                continue;
            }

            $normalized[$migration] = (int) $batch;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function executeRemoteInspect(RemoteEndpointProfile $profile, array $arguments): array
    {
        $remoteScript = rtrim($profile->projectPath, '/').'/'.self::INSPECT_SCRIPT;
        $remoteCommand = sprintf(
            'cd %s && %s %s %s',
            escapeshellarg($profile->projectPath),
            escapeshellarg($profile->phpBin),
            escapeshellarg($remoteScript),
            implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $arguments)),
        );

        $process = Process::fromShellCommandline(
            sprintf(
                'ssh -p %d -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s %s',
                $profile->sshPort,
                escapeshellarg($profile->sshTarget()),
                escapeshellarg($remoteCommand),
            ),
        );

        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            return [
                'error' => trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Remote inspect command failed.'),
            ];
        }

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return [
                'error' => 'Remote inspect returned invalid JSON.',
            ];
        }

        return $decoded;
    }
}
