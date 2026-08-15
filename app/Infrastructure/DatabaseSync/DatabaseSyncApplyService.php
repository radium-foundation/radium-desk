<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseSyncApplyService
{
    public function __construct(
        private readonly DatabaseSyncDryRunService $dryRunService,
        private readonly SchemaParityGate $schemaParityGate,
        private readonly SchemaColumnParityGate $columnParityGate,
        private readonly SchemaIndexParityGate $indexParityGate,
        private readonly VpsDarkGate $vpsDarkGate,
        private readonly ApplyLock $applyLock,
        private readonly CheckpointAuthority $checkpointAuthority,
        private readonly TableDeltaExtractor $extractor,
        private readonly ExtractFileTransporter $transporter,
        private readonly CutoverVerificationService $cutoverVerification,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(
        ?string $table = null,
        ?int $tier = null,
        ?string $generationId = null,
        bool $skipExtract = false,
    ): array {
        $manifest = new DatabaseSyncManifest;

        if ($manifest->direction !== 'hostinger_to_vps') {
            throw new RuntimeException('Database sync direction cannot be reversed.');
        }

        $generationId ??= now()->utc()->format('Ymd\THis\Z');

        $dryRun = $this->dryRunService->run($table, $tier);

        if ($dryRun->hasBlockers()) {
            throw new RuntimeException('Phase 1 dry-run prerequisite failed with blockers.');
        }

        $schemaParity = $this->schemaParityGate->compare($manifest);

        if (! $schemaParity->matched) {
            throw new RuntimeException('Migration parity gate failed.');
        }

        $columnBlockers = $this->columnParityGate->blockers($manifest);
        $indexBlockers = $this->indexParityGate->blockers($manifest);
        $darkBlockers = $this->vpsDarkGate->blockers($manifest);

        $blockers = array_merge($columnBlockers, $indexBlockers, $darkBlockers);

        if ($blockers !== []) {
            throw new RuntimeException(implode(' ', $blockers));
        }

        if ($this->applyLock->isLocked()) {
            throw new RuntimeException('Database sync apply lock is already held.');
        }

        $this->applyLock->acquire($generationId);

        try {
            $checkpoints = $this->checkpointAuthority->pullFromTarget($manifest);

            $extractReport = $skipExtract
                ? ['generation_id' => $generationId, 'skipped' => true, 'tables' => []]
                : $this->extractor->extract($manifest, $generationId, $table, $tier, $checkpoints);

            if (! $skipExtract) {
                $this->transporter->transfer($manifest, $generationId, $extractReport);
            }

            $applyReport = $this->invokeRemoteApply($manifest, $generationId, $table, $tier);
            $verification = $this->cutoverVerification->verifyAfterApply($table, $tier);

            return [
                'generation_id' => $generationId,
                'direction' => $manifest->direction,
                'dry_run' => [
                    'warnings' => $dryRun->warnings,
                    'blockers' => $dryRun->blockers,
                ],
                'extract' => $extractReport,
                'apply' => $applyReport,
                'gate3_verification' => $verification,
            ];
        } finally {
            $this->applyLock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeRemoteApply(
        DatabaseSyncManifest $manifest,
        string $generationId,
        ?string $table,
        ?int $tier,
    ): array {
        $tables = $manifest->filterTables($table, $tier);
        $arguments = [
            '--generation-id='.$generationId,
            '--tables='.implode(',', array_map(static fn (SyncTableDefinition $definition): string => $definition->name, $tables)),
        ];

        $remoteScript = rtrim($manifest->target->projectPath, '/').'/app/Infrastructure/DatabaseSync/Scripts/remote_apply.php';
        $remoteCommand = sprintf(
            'cd %s && %s %s %s',
            escapeshellarg($manifest->target->projectPath),
            escapeshellarg($manifest->target->phpBin),
            escapeshellarg($remoteScript),
            implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $arguments)),
        );

        $process = Process::fromShellCommandline(
            sprintf(
                'ssh -p %d -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s %s',
                $manifest->target->sshPort,
                escapeshellarg($manifest->target->sshTarget()),
                escapeshellarg($remoteCommand),
            ),
        );

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Remote apply command failed.'));
        }

        $decoded = json_decode(trim($process->getOutput()), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Remote apply returned invalid JSON.');
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            throw new RuntimeException($decoded['error']);
        }

        return $decoded;
    }
}
