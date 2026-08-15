<?php

namespace App\Infrastructure\DatabaseSync;

class SchemaColumnParityGate
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
                $sourceColumns = $this->remoteTableProbe->fetchTableColumns($manifest->source, $table->name);
                $targetColumns = $this->remoteTableProbe->fetchTableColumns($manifest->target, $table->name);
            } catch (\Throwable $exception) {
                $blockers[] = "Unable to compare columns for [{$table->name}]: {$exception->getMessage()}";

                continue;
            }

            $sourceOnly = array_diff_key($sourceColumns, $targetColumns);
            $targetOnly = array_diff_key($targetColumns, $sourceColumns);

            foreach ($sourceOnly as $column => $definition) {
                $blockers[] = "Column [{$table->name}.{$column}] exists on source only.";
            }

            foreach ($targetOnly as $column => $definition) {
                $blockers[] = "Column [{$table->name}.{$column}] exists on target only.";
            }

            foreach (array_intersect_key($sourceColumns, $targetColumns) as $column => $sourceDefinition) {
                $targetDefinition = $targetColumns[$column];

                if ($sourceDefinition !== $targetDefinition) {
                    $blockers[] = "Column type mismatch for [{$table->name}.{$column}]: source={$sourceDefinition}, target={$targetDefinition}.";
                }
            }
        }

        return $blockers;
    }
}
