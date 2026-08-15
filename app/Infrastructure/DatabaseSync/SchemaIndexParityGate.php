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

            $required = $this->requiredIndexes($table);

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
     * @return list<list<string>>
     */
    private function requiredIndexes(SyncTableDefinition $table): array
    {
        $indexes = [$table->primaryKey];

        foreach ($table->uniqueIndexes as $uniqueIndex) {
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
