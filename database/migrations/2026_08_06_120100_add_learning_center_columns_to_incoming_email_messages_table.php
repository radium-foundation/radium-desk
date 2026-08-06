<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return;
        }

        Schema::table('incoming_email_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('incoming_email_messages', 'importance')) {
                $table->string('importance', 32)->nullable()->after('classification');
            }

            if (! Schema::hasColumn('incoming_email_messages', 'learning_owner_user_id')) {
                $table->foreignId('learning_owner_user_id')
                    ->nullable()
                    ->after('importance')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('incoming_email_messages', 'suggested_assignee_user_id')) {
                $table->foreignId('suggested_assignee_user_id')
                    ->nullable()
                    ->after('learning_owner_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('incoming_email_messages', 'ira_decision')) {
                $table->string('ira_decision', 128)->nullable()->after('suggested_assignee_user_id');
            }

            if (! Schema::hasColumn('incoming_email_messages', 'ira_confidence')) {
                $table->unsignedTinyInteger('ira_confidence')->nullable()->after('ira_decision');
            }

            if (! Schema::hasColumn('incoming_email_messages', 'ira_reason')) {
                $table->string('ira_reason', 255)->nullable()->after('ira_confidence');
            }

            if (! Schema::hasColumn('incoming_email_messages', 'ira_explanation')) {
                $table->json('ira_explanation')->nullable()->after('ira_reason');
            }

            if (! Schema::hasColumn('incoming_email_messages', 'matched_learning_rule_id')) {
                $table->foreignId('matched_learning_rule_id')
                    ->nullable()
                    ->after('ira_explanation')
                    ->constrained('incoming_email_learning_rules')
                    ->nullOnDelete();
            }
        });

        if (! $this->hasNamedIndex('incoming_email_messages', 'iem_learning_owner_idx')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->index(['status', 'learning_owner_user_id', 'received_at'], 'iem_learning_owner_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return;
        }

        if ($this->hasNamedIndex('incoming_email_messages', 'iem_learning_owner_idx')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->dropIndex('iem_learning_owner_idx');
            });
        }

        $columns = [
            'matched_learning_rule_id',
            'ira_explanation',
            'ira_reason',
            'ira_confidence',
            'ira_decision',
            'suggested_assignee_user_id',
            'learning_owner_user_id',
            'importance',
        ];

        Schema::table('incoming_email_messages', function (Blueprint $table) use ($columns): void {
            foreach ($columns as $column) {
                if (! Schema::hasColumn('incoming_email_messages', $column)) {
                    continue;
                }

                if (in_array($column, [
                    'matched_learning_rule_id',
                    'suggested_assignee_user_id',
                    'learning_owner_user_id',
                ], true)) {
                    $table->dropConstrainedForeignId($column);
                } else {
                    $table->dropColumn($column);
                }
            }
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
