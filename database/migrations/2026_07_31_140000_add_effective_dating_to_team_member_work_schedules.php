<?php

use App\Models\TeamMemberWorkSchedule;
use App\Services\Operations\WorkCalendarService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated schedule versions: multiple rows per user, one active per date.
 * Existing single rows become open-ended versions (effective_to null).
 *
 * Production note: MySQL InnoDB uses the user_id UNIQUE index as the supporting
 * index for team_member_work_schedules_user_id_foreign. Drop that FK first,
 * then the unique index, then ensure a non-unique supporting index, then
 * recreate the FK. SQLite does not support dropping FKs by name — skip the
 * FK dance there and only drop the unique index.
 */
return new class extends Migration
{
    private const TABLE = 'team_member_work_schedules';

    public function up(): void
    {
        $this->addEffectiveDatingColumnsIfMissing();
        $this->backfillEffectiveDatingColumns();
        $this->dropEffectiveFromDefaultIfPresent();
        $this->replaceUserIdUniqueWithNonUniqueSupportingIndex();
        $this->addCompositeIndexesIfMissing();
    }

    public function down(): void
    {
        $keepIds = DB::table(self::TABLE)
            ->selectRaw('MAX(id) as id')
            ->groupBy('user_id')
            ->pluck('id');

        DB::table(self::TABLE)
            ->whereNotIn('id', $keepIds)
            ->delete();

        $this->dropIndexByColumnsIfExists(['user_id', 'effective_from']);
        $this->dropIndexByColumnsIfExists(['user_id', 'effective_to']);

        if (Schema::hasColumn(self::TABLE, 'created_by')) {
            if ($this->supportsNamedForeignKeyDrops()) {
                $this->dropForeignKeysOnColumns(['created_by']);
            }

            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn(['created_by']);
            });
        }

        foreach (['expected_working_minutes', 'effective_to', 'effective_from'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        $this->restoreUserIdUniqueConstraint();
    }

    private function addEffectiveDatingColumnsIfMissing(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'effective_from')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->date('effective_from')->default('2026-07-01')->after('user_id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'effective_to')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->date('effective_to')->nullable()->after('effective_from');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'expected_working_minutes')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedInteger('expected_working_minutes')->nullable()->after('weekly_off_days');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'created_by')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('expected_working_minutes')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    private function backfillEffectiveDatingColumns(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'effective_from')) {
            return;
        }

        $calendar = app(WorkCalendarService::class);

        TeamMemberWorkSchedule::query()->orderBy('id')->each(function (TeamMemberWorkSchedule $schedule) use ($calendar): void {
            $effectiveFrom = $schedule->effective_from?->toDateString()
                ?? $schedule->created_at?->toDateString()
                ?? '2026-07-01';

            $expectedMinutes = $schedule->expected_working_minutes;
            if ($expectedMinutes === null) {
                $expectedMinutes = $calendar->expectedWorkingMinutes($schedule);
            }

            DB::table(self::TABLE)
                ->where('id', $schedule->id)
                ->update([
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $schedule->effective_to?->toDateString(),
                    'expected_working_minutes' => $expectedMinutes,
                ]);
        });
    }

    private function dropEffectiveFromDefaultIfPresent(): void
    {
        if (! $this->isMysql() || ! Schema::hasColumn(self::TABLE, 'effective_from')) {
            return;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COLUMN_DEFAULT AS column_default
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, self::TABLE, 'effective_from'],
        );

        if ($row === null || $row->column_default === null) {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN effective_from DROP DEFAULT');
    }

    /**
     * MySQL cannot drop team_member_work_schedules_user_id_unique while
     * team_member_work_schedules_user_id_foreign still depends on it.
     */
    private function replaceUserIdUniqueWithNonUniqueSupportingIndex(): void
    {
        $userIdForeign = $this->supportsNamedForeignKeyDrops()
            ? $this->foreignKeyOnColumns(['user_id'])
            : null;

        $onDelete = $userIdForeign['on_delete'] ?? 'cascade';
        $onUpdate = $userIdForeign['on_update'] ?? 'no action';
        $foreignTable = $userIdForeign['foreign_table'] ?? 'users';
        $foreignColumns = $userIdForeign['foreign_columns'] ?? ['id'];

        if ($userIdForeign !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($userIdForeign): void {
                $table->dropForeign($userIdForeign['name']);
            });
        }

        $uniqueIndex = $this->indexMatchingColumns(['user_id'], unique: true);
        if ($uniqueIndex !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($uniqueIndex): void {
                $table->dropUnique($uniqueIndex['name']);
            });
        }

        // Prefer composite (user_id, effective_*) indexes; InnoDB can use the
        // leftmost prefix for the user_id foreign key. Fall back to user_id alone.
        $this->addCompositeIndexesIfMissing();

        if (! $this->hasIndexStartingWith(['user_id'])) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('user_id');
            });
        }

        if ($userIdForeign !== null && $this->foreignKeyOnColumns(['user_id']) === null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($foreignTable, $foreignColumns, $onDelete, $onUpdate): void {
                $foreign = $table->foreign('user_id')
                    ->references($foreignColumns[0] ?? 'id')
                    ->on($foreignTable);

                $this->applyForeignKeyActions($foreign, $onDelete, $onUpdate);
            });
        }
    }

    private function restoreUserIdUniqueConstraint(): void
    {
        $userIdForeign = $this->supportsNamedForeignKeyDrops()
            ? $this->foreignKeyOnColumns(['user_id'])
            : null;

        $onDelete = $userIdForeign['on_delete'] ?? 'cascade';
        $onUpdate = $userIdForeign['on_update'] ?? 'no action';
        $foreignTable = $userIdForeign['foreign_table'] ?? 'users';
        $foreignColumns = $userIdForeign['foreign_columns'] ?? ['id'];

        if ($userIdForeign !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($userIdForeign): void {
                $table->dropForeign($userIdForeign['name']);
            });
        }

        // Drop non-unique user_id-only indexes so unique can replace them.
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['primary'] ?? false) === true) {
                continue;
            }

            if (($index['columns'] ?? []) !== ['user_id']) {
                continue;
            }

            if (($index['unique'] ?? false) === true) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($index): void {
                $table->dropIndex($index['name']);
            });
        }

        if ($this->indexMatchingColumns(['user_id'], unique: true) === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('user_id');
            });
        }

        if ($this->supportsNamedForeignKeyDrops() && $this->foreignKeyOnColumns(['user_id']) === null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($foreignTable, $foreignColumns, $onDelete, $onUpdate): void {
                $foreign = $table->foreign('user_id')
                    ->references($foreignColumns[0] ?? 'id')
                    ->on($foreignTable);

                $this->applyForeignKeyActions($foreign, $onDelete, $onUpdate);
            });
        }
    }

    private function addCompositeIndexesIfMissing(): void
    {
        if (Schema::hasColumn(self::TABLE, 'effective_from')
            && $this->indexMatchingColumns(['user_id', 'effective_from']) === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['user_id', 'effective_from']);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'effective_to')
            && $this->indexMatchingColumns(['user_id', 'effective_to']) === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['user_id', 'effective_to']);
            });
        }
    }

    /**
     * @param  list<string>  $prefix
     */
    private function hasIndexStartingWith(array $prefix): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['primary'] ?? false) === true) {
                continue;
            }

            $columns = $index['columns'] ?? [];
            if (array_slice($columns, 0, count($prefix)) === $prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    private function foreignKeyOnColumns(array $columns): ?array
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === $columns) {
                return $foreignKey;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropForeignKeysOnColumns(array $columns): void
    {
        if (! $this->supportsNamedForeignKeyDrops()) {
            return;
        }

        while (($foreignKey = $this->foreignKeyOnColumns($columns)) !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign($foreignKey['name']);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    private function indexMatchingColumns(array $columns, ?bool $unique = null): ?array
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['primary'] ?? false) === true) {
                continue;
            }

            if (($index['columns'] ?? []) !== $columns) {
                continue;
            }

            if ($unique !== null && (bool) ($index['unique'] ?? false) !== $unique) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropIndexByColumnsIfExists(array $columns): void
    {
        $index = $this->indexMatchingColumns($columns);
        if ($index === null) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($index): void {
            if (($index['unique'] ?? false) === true) {
                $table->dropUnique($index['name']);

                return;
            }

            $table->dropIndex($index['name']);
        });
    }

    private function applyForeignKeyActions(mixed $foreign, string $onDelete, string $onUpdate): void
    {
        match (strtolower($onDelete)) {
            'cascade' => $foreign->cascadeOnDelete(),
            'set null' => $foreign->nullOnDelete(),
            'restrict' => $foreign->restrictOnDelete(),
            default => $foreign->cascadeOnDelete(),
        };

        match (strtolower($onUpdate)) {
            'cascade' => $foreign->cascadeOnUpdate(),
            'set null' => $foreign->nullOnUpdate(),
            'restrict' => $foreign->restrictOnUpdate(),
            default => null,
        };
    }

    private function isMysql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }

    private function supportsNamedForeignKeyDrops(): bool
    {
        // SQLite: "This database driver does not support dropping foreign keys by name."
        return $this->isMysql();
    }
};
