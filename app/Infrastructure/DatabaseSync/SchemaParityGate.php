<?php

namespace App\Infrastructure\DatabaseSync;

class SchemaParityGate
{
    public function __construct(
        private readonly RemoteTableProbe $remoteTableProbe,
    ) {}

    public function compare(DatabaseSyncManifest $manifest): SchemaParityResult
    {
        $warnings = [];
        $blockers = [];

        try {
            $sourceMigrations = $this->remoteTableProbe->fetchMigrationStatus($manifest->source);
        } catch (\Throwable $exception) {
            return new SchemaParityResult(
                matched: false,
                blockers: ['Unable to read source migration status: '.$exception->getMessage()],
            );
        }

        try {
            $targetMigrations = $this->remoteTableProbe->fetchMigrationStatus($manifest->target);
        } catch (\Throwable $exception) {
            return new SchemaParityResult(
                matched: false,
                blockers: ['Unable to read target migration status: '.$exception->getMessage()],
                sourceMigrations: $sourceMigrations,
            );
        }

        $sourceOnly = array_diff_key($sourceMigrations, $targetMigrations);
        $targetOnly = array_diff_key($targetMigrations, $sourceMigrations);
        $shared = array_intersect_key($sourceMigrations, $targetMigrations);

        foreach ($sourceOnly as $migration => $batch) {
            $blockers[] = "Migration present on source only: {$migration} (batch {$batch}).";
        }

        foreach ($targetOnly as $migration => $batch) {
            $blockers[] = "Migration present on target only: {$migration} (batch {$batch}).";
        }

        foreach ($shared as $migration => $sourceBatch) {
            $targetBatch = $targetMigrations[$migration];

            if ($sourceBatch !== $targetBatch) {
                $warnings[] = "Migration batch mismatch for {$migration}: source={$sourceBatch}, target={$targetBatch}.";
            }
        }

        return new SchemaParityResult(
            matched: $blockers === [],
            warnings: $warnings,
            blockers: $blockers,
            sourceMigrations: $sourceMigrations,
            targetMigrations: $targetMigrations,
        );
    }
}
