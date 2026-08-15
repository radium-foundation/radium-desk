<?php

namespace App\Infrastructure\DatabaseSync;

class CursorPredicateBuilder
{
    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array{sql: string, bindings: array<string, mixed>}
     */
    public function buildMutablePredicate(SyncTableDefinition $table, array $checkpoint): array
    {
        $updatedAtColumn = $table->updatedAtColumn ?? 'updated_at';
        $lastId = (int) ($checkpoint['last_id'] ?? 0);
        $lastUpdatedAt = $checkpoint['last_updated_at'] ?? '1970-01-01 00:00:00';
        $lastIdAtWatermark = (int) ($checkpoint['last_id_at_watermark'] ?? 0);

        $sql = <<<SQL
(
    id > :last_id
    OR {$updatedAtColumn} > :last_updated_at
    OR ({$updatedAtColumn} = :last_updated_at_equal AND id > :last_id_at_watermark)
)
SQL;

        return [
            'sql' => $sql,
            'bindings' => [
                'last_id' => $lastId,
                'last_updated_at' => $lastUpdatedAt,
                'last_updated_at_equal' => $lastUpdatedAt,
                'last_id_at_watermark' => $lastIdAtWatermark,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array{sql: string, bindings: array<string, mixed>}
     */
    public function buildAppendOnlyPredicate(array $checkpoint): array
    {
        return [
            'sql' => 'id > :last_id',
            'bindings' => [
                'last_id' => (int) ($checkpoint['last_id'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array{sql: string, bindings: array<string, mixed>}
     */
    public function buildCompositePkPredicate(SyncTableDefinition $table, array $checkpoint): array
    {
        $lastPk = $checkpoint['last_pk'] ?? null;

        if (! is_array($lastPk) || $lastPk === []) {
            return [
                'sql' => '1 = 1',
                'bindings' => [],
            ];
        }

        return $this->buildTupleGreaterThan($table->primaryKey, $lastPk, 'pk');
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array{sql: string, bindings: array<string, mixed>}
     */
    public function buildCreatedAtPkPredicate(SyncTableDefinition $table, array $checkpoint): array
    {
        $createdAtColumn = $this->quoteColumn($table->createdAtColumn ?? 'created_at');
        $lastCreatedAt = $checkpoint['last_created_at'] ?? '1970-01-01 00:00:00';
        $lastPk = is_array($checkpoint['last_pk'] ?? null) ? $checkpoint['last_pk'] : [];

        if ($lastPk === []) {
            $lastCreatedAt = $checkpoint['last_created_at'] ?? null;

            if (! is_string($lastCreatedAt) || $lastCreatedAt === '' || $lastCreatedAt === '1970-01-01 00:00:00') {
                return ['sql' => '1 = 1', 'bindings' => []];
            }

            return [
                'sql' => $this->quoteColumn($table->createdAtColumn ?? 'created_at').' > :last_created_at',
                'bindings' => ['last_created_at' => $lastCreatedAt],
            ];
        }

        $tuple = $this->buildTupleGreaterThan($table->primaryKey, $lastPk, 'pk');

        $sql = "({$createdAtColumn} > :last_created_at OR ({$createdAtColumn} = :last_created_at_equal AND ({$tuple['sql']})))";

        return [
            'sql' => $sql,
            'bindings' => array_merge(
                [
                    'last_created_at' => $lastCreatedAt,
                    'last_created_at_equal' => $lastCreatedAt,
                ],
                $tuple['bindings'],
            ),
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $lastValues
     * @return array{sql: string, bindings: array<string, mixed>}
     */
    public function buildTupleGreaterThan(array $columns, array $lastValues, string $prefix): array
    {
        if ($columns === []) {
            return ['sql' => '1 = 1', 'bindings' => []];
        }

        $clauses = [];
        $bindings = [];

        for ($i = 0, $count = count($columns); $i < $count; $i++) {
            $parts = [];

            for ($j = 0; $j < $i; $j++) {
                $bind = "{$prefix}_{$j}_eq_{$i}";
                $parts[] = $this->quoteColumn($columns[$j]).' = :'.$bind;
                $bindings[$bind] = $lastValues[$columns[$j]] ?? null;
            }

            $gtBind = "{$prefix}_{$i}_gt";
            $parts[] = $this->quoteColumn($columns[$i]).' > :'.$gtBind;
            $bindings[$gtBind] = $lastValues[$columns[$i]] ?? null;
            $clauses[] = '('.implode(' AND ', $parts).')';
        }

        return [
            'sql' => '('.implode(' OR ', $clauses).')',
            'bindings' => $bindings,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $left
     * @param  array<string, mixed>|null  $right
     * @param  list<string>  $columns
     */
    public function comparePkTuples(?array $left, ?array $right, array $columns): int
    {
        if ($left === null || $left === []) {
            return $right === null || $right === [] ? 0 : -1;
        }

        if ($right === null || $right === []) {
            return 1;
        }

        foreach ($columns as $column) {
            $leftRaw = $left[$column] ?? null;
            $rightRaw = $right[$column] ?? null;

            if (is_numeric($leftRaw) && is_numeric($rightRaw)) {
                $comparison = $leftRaw + 0 <=> $rightRaw + 0;
            } else {
                $comparison = (string) $leftRaw <=> (string) $rightRaw;
            }

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private function quoteColumn(string $column): string
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException("Invalid checkpoint column [{$column}].");
        }

        return $column;
    }
}
