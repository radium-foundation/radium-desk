<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;
use Symfony\Component\Process\Process;

class ExtractFileTransporter
{
    /**
     * @param  array<string, mixed>  $extractReport
     */
    public function transfer(DatabaseSyncManifest $manifest, string $generationId, array $extractReport): void
    {
        $sourceDirectory = $this->remoteInbox($manifest->source, $generationId);
        $targetDirectory = $this->remoteInbox($manifest->target, $generationId);
        $stagingDirectory = storage_path('app/private/db-sync/staging/'.$generationId);

        if (! is_dir($stagingDirectory) && ! mkdir($stagingDirectory, 0755, true) && ! is_dir($stagingDirectory)) {
            throw new RuntimeException("Unable to create extract staging directory [{$stagingDirectory}].");
        }

        $this->rsyncPull($manifest->source, $sourceDirectory, $stagingDirectory);
        $this->assertStagingComplete($stagingDirectory, $generationId, $extractReport);
        $this->ensureRemoteDirectory($manifest->target, $targetDirectory);
        $this->rsyncPush($manifest->target, $stagingDirectory, $targetDirectory);
    }

    /**
     * @param  array<string, mixed>  $extractReport
     */
    public function assertStagingComplete(string $stagingDirectory, string $generationId, array $extractReport): void
    {
        $manifestPath = rtrim($stagingDirectory, '/').'/'.$generationId.'.extract.json';

        if (! is_file($manifestPath)) {
            throw new RuntimeException('Extract files were not transferred to the VPS inbox.');
        }

        $tables = $extractReport['tables'] ?? [];

        if (! is_array($tables)) {
            return;
        }

        foreach ($tables as $tableReport) {
            if (! is_array($tableReport)) {
                continue;
            }

            $chunks = $tableReport['chunks'] ?? [];

            if (! is_array($chunks)) {
                continue;
            }

            foreach ($chunks as $chunk) {
                if (! is_array($chunk)) {
                    continue;
                }

                $fileName = $chunk['file_name'] ?? basename((string) ($chunk['file_path'] ?? ''));

                if (! is_string($fileName) || $fileName === '' || ! is_file(rtrim($stagingDirectory, '/').'/'.$fileName)) {
                    throw new RuntimeException('Extract files were not transferred to the VPS inbox.');
                }
            }
        }
    }

    private function remoteInbox(RemoteEndpointProfile $profile, string $generationId): string
    {
        return rtrim($profile->projectPath, '/').'/storage/app/private/db-sync/inbox/'.$generationId;
    }

    private function rsyncPull(RemoteEndpointProfile $source, string $remoteDirectory, string $stagingDirectory): void
    {
        $this->run(sprintf(
            'rsync -az -e %s %s %s',
            escapeshellarg($this->sshCommand($source)),
            escapeshellarg($source->sshTarget().':'.$remoteDirectory.'/'),
            escapeshellarg(rtrim($stagingDirectory, '/').'/'),
        ));
    }

    private function rsyncPush(RemoteEndpointProfile $target, string $stagingDirectory, string $remoteDirectory): void
    {
        $this->run(sprintf(
            'rsync -az -e %s %s %s',
            escapeshellarg($this->sshCommand($target)),
            escapeshellarg(rtrim($stagingDirectory, '/').'/'),
            escapeshellarg($target->sshTarget().':'.$remoteDirectory.'/'),
        ));
    }

    private function ensureRemoteDirectory(RemoteEndpointProfile $profile, string $directory): void
    {
        $this->run(sprintf(
            'ssh -p %d -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s %s',
            $profile->sshPort,
            escapeshellarg($profile->sshTarget()),
            escapeshellarg('mkdir -p '.escapeshellarg($directory)),
        ));
    }

    private function sshCommand(RemoteEndpointProfile $profile): string
    {
        return sprintf(
            'ssh -p %d -o BatchMode=yes -o StrictHostKeyChecking=accept-new',
            $profile->sshPort,
        );
    }

    private function run(string $command): void
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Extract file transfer failed.'));
        }
    }
}
