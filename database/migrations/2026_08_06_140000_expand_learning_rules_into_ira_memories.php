<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase M1 — expand-in-place rename of incoming_email_learning_rules → ira_memories.
 *
 * Preserves row IDs so matched_learning_rule_id continues to resolve.
 * Adds matched_ira_memory_id alongside the legacy FK.
 * Creates a compatibility VIEW named incoming_email_learning_rules for raw SQL / tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incoming_email_learning_rules') && ! Schema::hasTable('ira_memories')) {
            return;
        }

        if (! Schema::hasTable('ira_memories')) {
            $this->dropMatchedLearningRuleForeignKey();
            Schema::rename('incoming_email_learning_rules', 'ira_memories');
        }

        $this->expandIraMemoriesSchema();
        $this->backfillIraMemoriesFromLegacyColumns();
        $this->finalizeIraMemoriesColumns();
        $this->createIraMemoryRelationsTable();
        $this->addMatchedIraMemoryIdColumn();
        $this->restoreMatchedLearningRuleForeignKey();
        $this->createLearningRulesCompatibilityView();
    }

    public function down(): void
    {
        $this->dropLearningRulesCompatibilityView();

        if (Schema::hasTable('incoming_email_messages') && Schema::hasColumn('incoming_email_messages', 'matched_ira_memory_id')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('matched_ira_memory_id');
            });
        }

        Schema::dropIfExists('ira_memory_relations');

        if (! Schema::hasTable('ira_memories')) {
            return;
        }

        $this->dropMatchedLearningRuleForeignKey();

        if (Schema::hasColumn('ira_memories', 'pattern_kind') && ! Schema::hasColumn('ira_memories', 'rule_type')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                if (! Schema::hasColumn('ira_memories', 'enabled')) {
                    $table->boolean('enabled')->default(true);
                }
            });

            DB::table('ira_memories')->orderBy('id')->each(function (object $row): void {
                DB::table('ira_memories')->where('id', $row->id)->update([
                    'enabled' => ($row->status ?? null) === 'active',
                ]);
            });

            $this->dropIndexIfExists('ira_memories', 'ira_memories_unique_live_match');
            $this->dropIndexIfExists('ira_memories', 'ira_memories_match_idx');
            $this->dropIndexIfExists('ira_memories', 'ira_memories_type_browse_idx');
            $this->dropIndexIfExists('ira_memories', 'ira_memories_source_browse_idx');
            $this->dropIndexIfExists('ira_memories', 'ira_memories_times_used_idx');
            $this->dropIndexIfExists('ira_memories', 'ira_memories_last_used_idx');
            $this->dropIndexIfExists('ira_memories', 'ira_memories_merged_into_idx');
            $this->dropIndexIfExists('ira_memories', 'ira_memories_uuid_unique');

            $this->dropForeignKeyOnColumn('ira_memories', 'created_by_user_id');

            if (Schema::hasColumn('ira_memories', 'merged_into_memory_id')) {
                Schema::table('ira_memories', function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('merged_into_memory_id');
                });
            }

            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->renameColumn('pattern_kind', 'rule_type');
                $table->renameColumn('pattern_value', 'match_value');
                $table->renameColumn('decision_kind', 'decision_type');
                $table->renameColumn('created_by_user_id', 'created_by');
            });

            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->foreign('created_by')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });

            Schema::table('ira_memories', function (Blueprint $table): void {
                $drop = array_values(array_filter([
                    Schema::hasColumn('ira_memories', 'uuid') ? 'uuid' : null,
                    Schema::hasColumn('ira_memories', 'memory_type') ? 'memory_type' : null,
                    Schema::hasColumn('ira_memories', 'source') ? 'source' : null,
                    Schema::hasColumn('ira_memories', 'reason') ? 'reason' : null,
                    Schema::hasColumn('ira_memories', 'status') ? 'status' : null,
                    Schema::hasColumn('ira_memories', 'created_from') ? 'created_from' : null,
                    Schema::hasColumn('ira_memories', 'created_from_type') ? 'created_from_type' : null,
                    Schema::hasColumn('ira_memories', 'created_from_id') ? 'created_from_id' : null,
                    Schema::hasColumn('ira_memories', 'expires_at') ? 'expires_at' : null,
                    Schema::hasColumn('ira_memories', 'suggestion_origin') ? 'suggestion_origin' : null,
                    Schema::hasColumn('ira_memories', 'approval_status') ? 'approval_status' : null,
                    Schema::hasColumn('ira_memories', 'score') ? 'score' : null,
                    Schema::hasColumn('ira_memories', 'metadata') ? 'metadata' : null,
                    Schema::hasColumn('ira_memories', 'uniqueness_guard') ? 'uniqueness_guard' : null,
                    Schema::hasColumn('ira_memories', 'deleted_at') ? 'deleted_at' : null,
                ]));

                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });

            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->unique(
                    ['rule_type', 'match_value', 'decision_type'],
                    'iem_learning_rules_unique_match',
                );
                $table->index(['enabled', 'rule_type', 'match_value'], 'iem_learning_rules_match_idx');
                $table->index(['decision_type', 'enabled'], 'iem_learning_rules_decision_idx');
            });
        }

        Schema::rename('ira_memories', 'incoming_email_learning_rules');

        if (Schema::hasTable('incoming_email_messages') && Schema::hasColumn('incoming_email_messages', 'matched_learning_rule_id')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->foreign('matched_learning_rule_id')
                    ->references('id')
                    ->on('incoming_email_learning_rules')
                    ->nullOnDelete();
            });
        }
    }

    private function expandIraMemoriesSchema(): void
    {
        Schema::table('ira_memories', function (Blueprint $table): void {
            if (! Schema::hasColumn('ira_memories', 'uuid')) {
                $table->uuid('uuid')->nullable()->after('id');
            }
            if (! Schema::hasColumn('ira_memories', 'memory_type')) {
                $table->string('memory_type', 32)->nullable()->after('uuid');
            }
            if (! Schema::hasColumn('ira_memories', 'source')) {
                $table->string('source', 32)->nullable()->after('memory_type');
            }
            if (! Schema::hasColumn('ira_memories', 'reason')) {
                $table->text('reason')->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'status')) {
                $table->string('status', 32)->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'created_from')) {
                $table->string('created_from', 32)->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'created_from_type')) {
                $table->string('created_from_type', 64)->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'created_from_id')) {
                $table->unsignedBigInteger('created_from_id')->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'merged_into_memory_id')) {
                $table->foreignId('merged_into_memory_id')
                    ->nullable()
                    ->constrained('ira_memories')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('ira_memories', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'suggestion_origin')) {
                $table->string('suggestion_origin', 32)->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'approval_status')) {
                $table->string('approval_status', 32)->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'score')) {
                $table->decimal('score', 8, 4)->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'metadata')) {
                $table->json('metadata')->nullable();
            }
            if (! Schema::hasColumn('ira_memories', 'uniqueness_guard')) {
                $table->string('uniqueness_guard', 64)->default('live');
            }
            if (! Schema::hasColumn('ira_memories', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    private function backfillIraMemoriesFromLegacyColumns(): void
    {
        if (! Schema::hasColumn('ira_memories', 'rule_type') && Schema::hasColumn('ira_memories', 'pattern_kind')) {
            // Already finalized on a prior run.
            return;
        }

        DB::table('ira_memories')->orderBy('id')->each(function (object $row): void {
            $decisionType = (string) ($row->decision_type ?? $row->decision_kind ?? '');
            $enabled = array_key_exists('enabled', (array) $row)
                ? (bool) $row->enabled
                : (($row->status ?? null) === 'active');

            $memoryType = match ($decisionType) {
                'assign' => 'owner',
                'classification' => 'classification',
                'ignore' => 'ignore',
                'importance' => 'routing_pattern',
                'disposition' => 'disposition',
                default => 'classification',
            };

            DB::table('ira_memories')->where('id', $row->id)->update([
                'uuid' => $row->uuid ?: (string) Str::uuid(),
                'memory_type' => $row->memory_type ?: $memoryType,
                'source' => $row->source ?: 'email',
                'status' => $row->status ?: ($enabled ? 'active' : 'disabled'),
                'created_from' => $row->created_from ?: 'migration',
                'uniqueness_guard' => $row->uniqueness_guard ?: 'live',
            ]);
        });
    }

    private function finalizeIraMemoriesColumns(): void
    {
        if (Schema::hasColumn('ira_memories', 'rule_type') && ! Schema::hasColumn('ira_memories', 'pattern_kind')) {
            $this->dropIndexIfExists('ira_memories', 'iem_learning_rules_unique_match');
            $this->dropIndexIfExists('ira_memories', 'iem_learning_rules_match_idx');
            $this->dropIndexIfExists('ira_memories', 'iem_learning_rules_decision_idx');

            // Drop created_by FK before rename (MySQL).
            $this->dropForeignKeyOnColumn('ira_memories', 'created_by');

            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->renameColumn('rule_type', 'pattern_kind');
                $table->renameColumn('match_value', 'pattern_value');
                $table->renameColumn('decision_type', 'decision_kind');
                $table->renameColumn('created_by', 'created_by_user_id');
            });

            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->foreign('created_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('ira_memories', 'enabled')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->dropColumn('enabled');
            });
        }

        // Tighten nullability where safe.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE ira_memories MODIFY uuid CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE ira_memories MODIFY memory_type VARCHAR(32) NOT NULL');
            DB::statement('ALTER TABLE ira_memories MODIFY source VARCHAR(32) NOT NULL');
            DB::statement('ALTER TABLE ira_memories MODIFY status VARCHAR(32) NOT NULL');
            DB::statement('ALTER TABLE ira_memories MODIFY created_from VARCHAR(32) NOT NULL');
            DB::statement("ALTER TABLE ira_memories MODIFY uniqueness_guard VARCHAR(64) NOT NULL DEFAULT 'live'");
        }

        if (! $this->hasNamedIndex('ira_memories', 'ira_memories_uuid_unique') && Schema::hasColumn('ira_memories', 'uuid')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->unique('uuid', 'ira_memories_uuid_unique');
            });
        }

        if (! $this->hasNamedIndex('ira_memories', 'ira_memories_unique_live_match')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->unique(
                    ['pattern_kind', 'pattern_value', 'decision_kind', 'uniqueness_guard'],
                    'ira_memories_unique_live_match',
                );
            });
        }

        if (! $this->hasNamedIndex('ira_memories', 'ira_memories_match_idx')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->index(['status', 'pattern_kind', 'pattern_value'], 'ira_memories_match_idx');
            });
        }

        if (! $this->hasNamedIndex('ira_memories', 'ira_memories_type_browse_idx')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->index(['memory_type', 'status', 'last_used_at'], 'ira_memories_type_browse_idx');
            });
        }

        if (! $this->hasNamedIndex('ira_memories', 'ira_memories_source_browse_idx')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->index(['source', 'status'], 'ira_memories_source_browse_idx');
            });
        }

        if (! $this->hasNamedIndex('ira_memories', 'ira_memories_times_used_idx')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->index(['times_used'], 'ira_memories_times_used_idx');
            });
        }

        if (! $this->hasNamedIndex('ira_memories', 'ira_memories_last_used_idx')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->index(['last_used_at'], 'ira_memories_last_used_idx');
            });
        }

        if (! $this->hasNamedIndex('ira_memories', 'ira_memories_merged_into_idx')) {
            Schema::table('ira_memories', function (Blueprint $table): void {
                $table->index(['merged_into_memory_id'], 'ira_memories_merged_into_idx');
            });
        }
    }

    private function createIraMemoryRelationsTable(): void
    {
        if (Schema::hasTable('ira_memory_relations')) {
            return;
        }

        Schema::create('ira_memory_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('memory_id')->constrained('ira_memories')->cascadeOnDelete();
            $table->foreignId('related_memory_id')->constrained('ira_memories')->cascadeOnDelete();
            $table->string('relation_type', 32);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['memory_id', 'related_memory_id', 'relation_type'],
                'ira_memory_relations_unique',
            );
        });
    }

    private function addMatchedIraMemoryIdColumn(): void
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return;
        }

        if (! Schema::hasColumn('incoming_email_messages', 'matched_ira_memory_id')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->foreignId('matched_ira_memory_id')
                    ->nullable()
                    ->after('matched_learning_rule_id')
                    ->constrained('ira_memories')
                    ->nullOnDelete();
            });
        }

        // In-place rename keeps IDs stable — mirror legacy matches into the new column.
        if (
            Schema::hasColumn('incoming_email_messages', 'matched_learning_rule_id')
            && Schema::hasColumn('incoming_email_messages', 'matched_ira_memory_id')
        ) {
            DB::table('incoming_email_messages')
                ->whereNotNull('matched_learning_rule_id')
                ->whereNull('matched_ira_memory_id')
                ->orderBy('id')
                ->each(function (object $row): void {
                    DB::table('incoming_email_messages')->where('id', $row->id)->update([
                        'matched_ira_memory_id' => $row->matched_learning_rule_id,
                    ]);
                });
        }
    }

    private function dropMatchedLearningRuleForeignKey(): void
    {
        if (
            ! Schema::hasTable('incoming_email_messages')
            || ! Schema::hasColumn('incoming_email_messages', 'matched_learning_rule_id')
        ) {
            return;
        }

        $this->dropForeignKeyOnColumn('incoming_email_messages', 'matched_learning_rule_id');
    }

    private function restoreMatchedLearningRuleForeignKey(): void
    {
        if (
            ! Schema::hasTable('incoming_email_messages')
            || ! Schema::hasColumn('incoming_email_messages', 'matched_learning_rule_id')
            || ! Schema::hasTable('ira_memories')
        ) {
            return;
        }

        if ($this->hasForeignKeyOnColumn('incoming_email_messages', 'matched_learning_rule_id')) {
            return;
        }

        Schema::table('incoming_email_messages', function (Blueprint $table): void {
            $table->foreign('matched_learning_rule_id')
                ->references('id')
                ->on('ira_memories')
                ->nullOnDelete();
        });
    }

    private function createLearningRulesCompatibilityView(): void
    {
        if (Schema::hasTable('incoming_email_learning_rules')) {
            // Physical table still present — do not mask it with a view.
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        $this->dropLearningRulesCompatibilityView();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE VIEW incoming_email_learning_rules AS
                SELECT
                    id,
                    pattern_kind AS rule_type,
                    pattern_value AS match_value,
                    decision_kind AS decision_type,
                    decision_value,
                    confidence,
                    created_by_user_id AS created_by,
                    times_used,
                    last_used_at,
                    CASE WHEN status = 'active' THEN 1 ELSE 0 END AS enabled,
                    created_at,
                    updated_at
                FROM ira_memories
                WHERE deleted_at IS NULL
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            CREATE VIEW incoming_email_learning_rules AS
            SELECT
                id,
                pattern_kind AS rule_type,
                pattern_value AS match_value,
                decision_kind AS decision_type,
                decision_value,
                confidence,
                created_by_user_id AS created_by,
                times_used,
                last_used_at,
                CASE WHEN status = 'active' THEN 1 ELSE 0 END AS enabled,
                created_at,
                updated_at
            FROM ira_memories
            WHERE deleted_at IS NULL
        SQL);
    }

    private function dropLearningRulesCompatibilityView(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DROP VIEW IF EXISTS incoming_email_learning_rules');

            return;
        }

        DB::statement('DROP VIEW IF EXISTS incoming_email_learning_rules');
    }

    private function dropForeignKeyOnColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $columns = $foreignKey['columns'] ?? [];

            if ($columns !== [$column]) {
                continue;
            }

            $name = $foreignKey['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropForeign($name);
            });
        }
    }

    private function hasForeignKeyOnColumn(string $table, string $column): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return false;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->hasNamedIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
        });
    }

    private function hasNamedIndex(string $table, string $indexName): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
