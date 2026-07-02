<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<array{table: string, columns: list<string>}>
     */
    private const INDEXES = [
        ['table' => 'audit_logs', 'columns' => ['event', 'created_at']],
        ['table' => 'audit_logs', 'columns' => ['auditable_type', 'auditable_id', 'created_at']],
        ['table' => 'incidents', 'columns' => ['status', 'assigned_to_user_id']],
        ['table' => 'incidents', 'columns' => ['status', 'created_at']],
        ['table' => 'remarks', 'columns' => ['remarkable_type', 'remarkable_id', 'created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $definition) {
            $this->addIndexIfMissing($definition['table'], $definition['columns']);
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $definition) {
            $this->dropIndexIfPresent($definition['table'], $definition['columns']);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $table, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->hasIndex($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->index($columns);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropIndexIfPresent(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $indexName = $this->resolveIndexName($table, $columns);

        if ($indexName === null) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndex(string $table, array $columns): bool
    {
        return $this->resolveIndexName($table, $columns) !== null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function resolveIndexName(string $table, array $columns): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return $index['name'] ?? null;
            }
        }

        return null;
    }
};
