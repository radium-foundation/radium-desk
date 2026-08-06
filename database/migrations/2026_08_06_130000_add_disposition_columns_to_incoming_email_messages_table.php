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
            if (! Schema::hasColumn('incoming_email_messages', 'disposition')) {
                $table->string('disposition', 32)->nullable()->after('matched_learning_rule_id');
            }

            if (! Schema::hasColumn('incoming_email_messages', 'disposition_reason')) {
                $table->string('disposition_reason', 64)->nullable()->after('disposition');
            }

            if (! Schema::hasColumn('incoming_email_messages', 'disposed_at')) {
                $table->timestamp('disposed_at')->nullable()->after('disposition_reason');
            }

            if (! Schema::hasColumn('incoming_email_messages', 'disposed_by_user_id')) {
                $table->foreignId('disposed_by_user_id')
                    ->nullable()
                    ->after('disposed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        if (! $this->hasNamedIndex('incoming_email_messages', 'iem_disposition_status_idx')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->index(['status', 'disposition', 'received_at'], 'iem_disposition_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return;
        }

        if ($this->hasNamedIndex('incoming_email_messages', 'iem_disposition_status_idx')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->dropIndex('iem_disposition_status_idx');
            });
        }

        Schema::table('incoming_email_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('incoming_email_messages', 'disposed_by_user_id')) {
                $table->dropConstrainedForeignId('disposed_by_user_id');
            }

            foreach (['disposed_at', 'disposition_reason', 'disposition'] as $column) {
                if (Schema::hasColumn('incoming_email_messages', $column)) {
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
