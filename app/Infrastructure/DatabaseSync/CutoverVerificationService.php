<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;

class CutoverVerificationService
{
    public function __construct(
        private readonly DatabaseSyncDryRunService $dryRunService,
        private readonly RemoteTableProbe $remoteTableProbe,
    ) {}

    /**
     * Read-only Gate 3 probes. Fail closed when state cannot be established.
     * Count drift is reported, not treated as an apply blocker during prep generations.
     *
     * @return array<string, mixed>
     */
    public function verifyAfterApply(?string $table = null, ?int $tier = null): array
    {
        $manifest = new DatabaseSyncManifest;
        $errors = [];

        try {
            $dryRun = $this->dryRunService->run($table, $tier);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Gate 3 verification could not establish expected state: '.$exception->getMessage());
        }

        if ($dryRun->hasBlockers()) {
            $errors = array_merge($errors, $dryRun->blockers);
        }

        $referenceSequences = $this->verifyReferenceSequences($manifest);
        if (($referenceSequences['error'] ?? null) !== null) {
            $errors[] = (string) $referenceSequences['error'];
        }

        $softDeletes = $this->verifySoftDeleteCounts($manifest, $tier);
        if (($softDeletes['error'] ?? null) !== null) {
            $errors[] = (string) $softDeletes['error'];
        }

        $reconciliation = $this->verifyReconciliationReadOnly($manifest);
        if (($reconciliation['error'] ?? null) !== null) {
            $errors[] = (string) $reconciliation['error'];
        }

        if ($errors !== []) {
            throw new RuntimeException(
                'Gate 3 verification could not establish expected state: '.implode(' ', $errors),
            );
        }

        return [
            'established' => true,
            'passed' => $dryRun->blockers === []
                && ($referenceSequences['matched'] ?? false)
                && ($softDeletes['matched'] ?? false),
            'dry_run_warnings' => $dryRun->warnings,
            'reference_sequences' => $referenceSequences,
            'soft_delete_counts' => $softDeletes,
            'reconciliation' => $reconciliation,
            'operational_prerequisites' => [
                'Process-list dark-status does not prove DNS, cron, or scheduler configuration.',
                'Cashfree and RadiumBox checks are read-only verification; they must not recover or replay.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(?int $tier = 1): array
    {
        return $this->verifyAfterApply(null, $tier);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyReferenceSequences(DatabaseSyncManifest $manifest): array
    {
        try {
            $source = $this->remoteTableProbe->fetchReferenceSequenceValues($manifest->source);
            $target = $this->remoteTableProbe->fetchReferenceSequenceValues($manifest->target);
        } catch (\Throwable $exception) {
            return [
                'matched' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $mismatches = [];

        foreach ($source as $name => $value) {
            $targetValue = $target[$name] ?? null;

            if ($targetValue === null) {
                $mismatches[] = "Missing sequence [{$name}] on target.";

                continue;
            }

            if ((int) $targetValue < (int) $value) {
                $mismatches[] = "Sequence [{$name}] target value {$targetValue} is behind source {$value}.";
            }
        }

        return [
            'matched' => $mismatches === [],
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verifySoftDeleteCounts(DatabaseSyncManifest $manifest, ?int $tier): array
    {
        $mismatches = [];

        try {
            foreach ($manifest->tablesForTier($tier) as $table) {
                if (! $table->softDeletes) {
                    continue;
                }

                $source = $this->remoteTableProbe->fetchSoftDeleteCount($manifest->source, $table->name);
                $target = $this->remoteTableProbe->fetchSoftDeleteCount($manifest->target, $table->name);

                if ($source !== $target) {
                    $mismatches[] = "Soft-delete count mismatch for [{$table->name}]: source={$source}, target={$target}.";
                }
            }
        } catch (\Throwable $exception) {
            return [
                'matched' => false,
                'mismatches' => $mismatches,
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'matched' => $mismatches === [],
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyReconciliationReadOnly(DatabaseSyncManifest $manifest): array
    {
        try {
            $source = $this->remoteTableProbe->fetchReconciliationSnapshot($manifest->source);
            $target = $this->remoteTableProbe->fetchReconciliationSnapshot($manifest->target);
        } catch (\Throwable $exception) {
            return [
                'matched' => false,
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'matched' => true,
            'source' => $source,
            'target' => $target,
            'mode' => 'read_only',
        ];
    }
}
