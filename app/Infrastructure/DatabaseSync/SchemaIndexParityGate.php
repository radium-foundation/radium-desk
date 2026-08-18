<?php

namespace App\Infrastructure\DatabaseSync;

class SchemaIndexParityGate
{
    public function __construct(
        private readonly RemoteTableProbe $remoteTableProbe,
    ) {}

    /**
     * @return list<string>
     */
    public function blockers(DatabaseSyncManifest $manifest): array
    {
        $blockers = [];

        foreach ($manifest->tablesInSyncOrder() as $table) {
            try {
                $sourceIndexes = $this->remoteTableProbe->fetchTableIndexes($manifest->source, $table->name);
                $targetIndexes = $this->remoteTableProbe->fetchTableIndexes($manifest->target, $table->name);
            } catch (\Throwable $exception) {
                $blockers[] = "Unable to compare indexes for [{$table->name}]: {$exception->getMessage()}";

                continue;
            }

            $required = $this->requiredPhysicalIndexes($table);

            foreach ($required as $indexColumns) {
                $signature = $this->signature($indexColumns);

                if (! isset($sourceIndexes[$signature])) {
                    $blockers[] = "Index [{$table->name}(".implode(',', $indexColumns).')] missing on source.';
                }

                if (! isset($targetIndexes[$signature])) {
                    $blockers[] = "Index [{$table->name}(".implode(',', $indexColumns).')] missing on target.';
                }
            }
        }

        return $blockers;
    }

    /**
     * Physical UNIQUE indexes only. Business unique keys are enforced by UniqueConflictChecker.
     *
     * @return list<list<string>>
     */
    private function requiredPhysicalIndexes(SyncTableDefinition $table): array
    {
        $indexes = [$table->primaryKey];

        foreach ($table->physicalUniqueIndexes as $uniqueIndex) {
            if ($this->signature($uniqueIndex) === $this->signature($table->primaryKey)) {
                continue;
            }

            $indexes[] = $uniqueIndex;
        }

        return $indexes;
    }

    /**
     * @param  list<string>  $columns
     */
    private function signature(array $columns): string
    {
        return implode('|', $columns);
    }
}
