<?php

namespace App\Infrastructure\DatabaseSync;

use Illuminate\Support\Facades\DB;

class UniqueConflictChecker
{
    /**
     * @return array<string, mixed>|null
     */
    public function detectConflict(SyncTableDefinition $table, array $incomingRow): ?array
    {
        if ($table->businessUniqueKeys === []) {
            return null;
        }

        foreach ($table->businessUniqueKeys as $indexColumns) {
            if ($this->shouldSkipIndex($incomingRow, $indexColumns)) {
                continue;
            }

            $query = DB::table($table->name);

            foreach ($indexColumns as $column) {
                $query->where($column, $incomingRow[$column]);
            }

            $existing = $query->first();

            if ($existing === null) {
                continue;
            }

            $existingRow = (array) $existing;

            if (! $this->samePrimaryKey($table, $incomingRow, $existingRow)) {
                return [
                    'table' => $table->name,
                    'unique_index' => $indexColumns,
                    'key_values' => $this->keyValues($incomingRow, $indexColumns),
                    'source_pk' => $this->primaryKeyValues($table, $incomingRow),
                    'target_pk' => $this->primaryKeyValues($table, $existingRow),
                ];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $indexColumns
     */
    private function shouldSkipIndex(array $row, array $indexColumns): bool
    {
        foreach ($indexColumns as $column) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function samePrimaryKey(SyncTableDefinition $table, array $left, array $right): bool
    {
        foreach ($table->primaryKey as $column) {
            $leftValue = $left[$column] ?? null;
            $rightValue = $right[$column] ?? null;

            if ((string) $leftValue !== (string) $rightValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function keyValues(array $row, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = $row[$column] ?? null;
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function primaryKeyValues(SyncTableDefinition $table, array $row): array
    {
        $values = [];

        foreach ($table->primaryKey as $column) {
            $values[$column] = $row[$column] ?? null;
        }

        return $values;
    }
}
