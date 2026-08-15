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
     * @return array<string, string>
     */
    public function fetchTableColumns(RemoteEndpointProfile $profile, string $table): array
    {
        $payload = $this->executeRemoteInspect($profile, [
            '--action=table-columns',
            '--table='.$table,
        ]);

        if (isset($payload['error']) && is_string($payload['error'])) {
            throw new RuntimeException($payload['error']);
        }

        $columns = $payload['columns'] ?? null;

        if (! is_array($columns)) {
            throw new RuntimeException('Remote column list response was invalid.');
        }

        $normalized = [];

        foreach ($columns as $column => $definition) {
            if (is_string($column) && is_string($definition)) {
                $normalized[$column] = $definition;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, list<string>>
     */
    public function fetchTableIndexes(RemoteEndpointProfile $profile, string $table): array
    {
        $payload = $this->executeRemoteInspect($profile, [
            '--action=table-indexes',
            '--table='.$table,
        ]);

        if (isset($payload['error']) && is_string($payload['error'])) {
            throw new RuntimeException($payload['error']);
        }

        $indexes = $payload['indexes'] ?? null;

        if (! is_array($indexes)) {
            throw new RuntimeException('Remote index list response was invalid.');
        }

        $normalized = [];

        foreach ($indexes as $signature => $columns) {
            if (! is_string($signature) || ! is_array($columns)) {
                continue;
            }

            $normalized[$signature] = array_values(array_map(strval(...), $columns));
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, int>
     */
    public function fetchReferenceSequenceValues(RemoteEndpointProfile $profile): array
    {
        $payload = $this->executeRemoteInspect($profile, ['--action=reference-sequences']);

        if (isset($payload['error']) && is_string($payload['error'])) {
            throw new RuntimeException($payload['error']);
        }

        $sequences = $payload['sequences'] ?? null;

        if (! is_array($sequences)) {
            throw new RuntimeException('Remote reference sequence response was invalid.');
        }

        $normalized = [];

        foreach ($sequences as $name => $value) {
            if (is_string($name)) {
                $normalized[$name] = (int) $value;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    public function fetchSoftDeleteCount(RemoteEndpointProfile $profile, string $table): int
    {
        $payload = $this->executeRemoteInspect($profile, [
            '--action=soft-delete-count',
            '--table='.$table,
        ]);

        if (isset($payload['error']) && is_string($payload['error'])) {
            throw new RuntimeException($payload['error']);
        }

        return (int) ($payload['count'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchDarkStatus(RemoteEndpointProfile $profile): array
    {
        $payload = $this->executeRemoteInspect($profile, ['--action=dark-status']);

        if (isset($payload['error']) && is_string($payload['error'])) {
            throw new RuntimeException($payload['error']);
        }

        $active = $payload['active'] ?? [];

        if (! is_array($active)) {
            throw new RuntimeException('Remote VPS dark-status response was invalid.');
        }

        return [
            'dark' => (bool) ($payload['dark'] ?? false),
            'active' => array_values(array_filter($active, static fn ($command): bool => is_string($command) && $command !== '')),
            'process_list_ok' => ($payload['process_list_ok'] ?? false) === true,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fetchCheckpoints(RemoteEndpointProfile $profile): array
    {
        $payload = $this->executeRemoteInspect($profile, ['--action=export-checkpoints']);

        if (isset($payload['error']) && is_string($payload['error'])) {
            throw new RuntimeException($payload['error']);
        }

        $checkpoints = $payload['checkpoints'] ?? null;

        if (! is_array($checkpoints)) {
            throw new RuntimeException('Remote checkpoint export was invalid.');
        }

        $normalized = [];

        foreach ($checkpoints as $table => $state) {
            if (is_string($table) && is_array($state)) {
                $normalized[$table] = $state;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchReconciliationSnapshot(RemoteEndpointProfile $profile): array
    {
        $payload = $this->executeRemoteInspect($profile, ['--action=reconciliation-readonly']);

        if (isset($payload['error']) && is_string($payload['error'])) {
            throw new RuntimeException($payload['error']);
        }

        if (! isset($payload['orders_count'])) {
            throw new RuntimeException('Remote reconciliation snapshot was invalid.');
        }

        return $payload;
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
