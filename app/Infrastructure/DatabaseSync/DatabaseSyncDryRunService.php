<?php

namespace App\Infrastructure\DatabaseSync;

class DatabaseSyncDryRunService
{
    public function __construct(
        private readonly RemoteTableProbe $remoteTableProbe,
        private readonly SchemaParityGate $schemaParityGate,
        private readonly CheckpointStore $checkpointStore,
    ) {}

    /**
     * @return list<SyncTableDefinition>
     */
    public function resolveTables(?string $table, ?int $tier): array
    {
        $manifest = new DatabaseSyncManifest;

        return $manifest->filterTables($table, $tier);
    }

    public function run(?string $table = null, ?int $tier = null): SyncVerificationReport
    {
        $manifest = new DatabaseSyncManifest;
        $tables = $manifest->filterTables($table, $tier);
        $schemaParity = $this->schemaParityGate->compare($manifest);

        $warnings = [];
        $blockers = [];
        $tableRows = [];

        if (! $schemaParity->matched) {
            $blockers = array_merge($blockers, $schemaParity->blockers);
        }

        foreach ($tables as $definition) {
            $sourceProbe = $this->remoteTableProbe->probeTable($manifest->source, $definition);
            $targetProbe = $this->remoteTableProbe->probeTable($manifest->target, $definition);

            if (! $sourceProbe->successful()) {
                $blockers[] = "Source probe failed for [{$definition->name}]: {$sourceProbe->error}";
            }

            if (! $targetProbe->successful()) {
                $blockers[] = "Target probe failed for [{$definition->name}]: {$targetProbe->error}";
            }

            $sourceCount = $sourceProbe->count;
            $targetCount = $targetProbe->count;
            $countDrift = ($sourceCount !== null && $targetCount !== null)
                ? $sourceCount - $targetCount
                : null;

            if ($countDrift !== null && $countDrift > 0) {
                $warnings[] = "Table [{$definition->name}] source is ahead by {$countDrift} row(s).";
            } elseif ($countDrift !== null && $countDrift < 0) {
                $warnings[] = "Table [{$definition->name}] target is ahead by ".abs($countDrift).' row(s).';
            }

            if ($definition->strategy === SyncCursorStrategy::CompositePk) {
                $warnings[] = "Table [{$definition->name}] uses composite primary key; dry-run reports counts and created_at watermarks only.";
            }

            if ($definition->strategy === SyncCursorStrategy::FullReplace) {
                $warnings[] = "Table [{$definition->name}] uses full_replace strategy; Phase 1 reports counts only.";
            }

            $tableRows[] = [
                'table' => $definition->name,
                'tier' => $definition->tier,
                'sync_order' => $definition->syncOrder,
                'cursor_strategy' => $definition->strategy->value,
                'primary_key' => $definition->primaryKey,
                'unique_indexes' => $definition->uniqueIndexes,
                'unique_keys' => $definition->flattenedUniqueKeys(),
                'source' => $sourceProbe->toArray(),
                'target' => $targetProbe->toArray(),
                'count_drift' => $countDrift,
            ];
        }

        $report = new SyncVerificationReport(
            generatedAt: now()->toIso8601String(),
            direction: $manifest->direction,
            source: $this->endpointSummary($manifest->source),
            target: $this->endpointSummary($manifest->target),
            schemaParity: $schemaParity,
            tables: $tableRows,
            warnings: array_values(array_unique($warnings)),
            blockers: array_values(array_unique($blockers)),
        );

        $this->checkpointStore->recordDryRun([
            'generated_at' => $report->generatedAt,
            'table_count' => count($tableRows),
            'warnings' => count($report->warnings),
            'blockers' => count($report->blockers),
            'schema_parity_matched' => $schemaParity->matched,
        ]);

        return $report;
    }

    /**
     * @return array<string, string|int>
     */
    private function endpointSummary(RemoteEndpointProfile $profile): array
    {
        return [
            'name' => $profile->name,
            'label' => $profile->label,
            'ssh_host' => $profile->sshHost,
            'ssh_port' => $profile->sshPort,
            'ssh_user' => $profile->sshUser,
            'project_path' => $profile->projectPath,
        ];
    }
}
