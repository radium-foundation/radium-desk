<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Phase 1 reply + classification schema.
 *
 * Idempotent: safe when a prior run partially applied (e.g. classification
 * column exists but later CREATE TABLE failed on MySQL identifier limits).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureIncomingEmailClassification();
        $this->ensureOutgoingEmailMessagesTable();
        $this->ensureIncomingEmailIgnoreStatsTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_email_ignore_stats');
        Schema::dropIfExists('outgoing_email_messages');

        if (! Schema::hasTable('incoming_email_messages')) {
            return;
        }

        if ($this->hasNamedIndex('incoming_email_messages', 'iem_classification_idx')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->dropIndex('iem_classification_idx');
            });
        }

        if (Schema::hasColumn('incoming_email_messages', 'classification')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->dropColumn('classification');
            });
        }
    }

    private function ensureIncomingEmailClassification(): void
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return;
        }

        if (! Schema::hasColumn('incoming_email_messages', 'classification')) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                if (Schema::hasColumn('incoming_email_messages', 'ignore_reason')) {
                    $table->string('classification', 64)->nullable()->after('ignore_reason');
                } else {
                    $table->string('classification', 64)->nullable();
                }
            });
        }

        if (
            Schema::hasColumn('incoming_email_messages', 'classification')
            && ! $this->hasNamedIndex('incoming_email_messages', 'iem_classification_idx')
        ) {
            Schema::table('incoming_email_messages', function (Blueprint $table): void {
                $table->index('classification', 'iem_classification_idx');
            });
        }
    }

    private function ensureOutgoingEmailMessagesTable(): void
    {
        if (Schema::hasTable('outgoing_email_messages')) {
            $this->ensureOutgoingEmailMessageColumns();

            return;
        }

        Schema::create('outgoing_email_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('in_reply_to_incoming_email_message_id')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('mailbox', 255);
            $table->string('to_email', 255);
            $table->string('subject', 998)->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->text('preview')->nullable();
            $table->string('thread_id', 255)->nullable();
            $table->string('rfc_message_id', 512)->nullable();
            $table->string('provider', 32)->default('gmail');
            $table->string('provider_message_id', 255)->nullable();
            $table->string('template_key', 64)->nullable();
            $table->unsignedBigInteger('sent_by_user_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 32)->default('queued');
            $table->text('error')->nullable();
            $table->timestamps();

            // Short FK names — MySQL identifier limit is 64 characters.
            $table->foreign('in_reply_to_incoming_email_message_id', 'oem_in_reply_to_iem_fk')
                ->references('id')
                ->on('incoming_email_messages')
                ->nullOnDelete();
            $table->foreign('incident_id', 'oem_incident_fk')
                ->references('id')
                ->on('incidents')
                ->nullOnDelete();
            $table->foreign('order_id', 'oem_order_fk')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();
            $table->foreign('sent_by_user_id', 'oem_sent_by_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('thread_id', 'oem_thread_id_idx');
            $table->index('provider_message_id', 'oem_provider_message_id_idx');
            $table->index('order_id', 'oem_order_id_idx');
            $table->index(['status', 'sent_at'], 'oem_status_sent_idx');
        });
    }

    private function ensureOutgoingEmailMessageColumns(): void
    {
        $columns = [
            'in_reply_to_incoming_email_message_id' => function (Blueprint $table): void {
                $table->unsignedBigInteger('in_reply_to_incoming_email_message_id')->nullable();
            },
            'incident_id' => function (Blueprint $table): void {
                $table->unsignedBigInteger('incident_id')->nullable();
            },
            'order_id' => function (Blueprint $table): void {
                $table->unsignedBigInteger('order_id')->nullable();
            },
            'mailbox' => function (Blueprint $table): void {
                $table->string('mailbox', 255)->default('');
            },
            'to_email' => function (Blueprint $table): void {
                $table->string('to_email', 255)->default('');
            },
            'subject' => function (Blueprint $table): void {
                $table->string('subject', 998)->nullable();
            },
            'body_html' => function (Blueprint $table): void {
                $table->longText('body_html')->nullable();
            },
            'body_text' => function (Blueprint $table): void {
                $table->longText('body_text')->nullable();
            },
            'preview' => function (Blueprint $table): void {
                $table->text('preview')->nullable();
            },
            'thread_id' => function (Blueprint $table): void {
                $table->string('thread_id', 255)->nullable();
            },
            'rfc_message_id' => function (Blueprint $table): void {
                $table->string('rfc_message_id', 512)->nullable();
            },
            'provider' => function (Blueprint $table): void {
                $table->string('provider', 32)->default('gmail');
            },
            'provider_message_id' => function (Blueprint $table): void {
                $table->string('provider_message_id', 255)->nullable();
            },
            'template_key' => function (Blueprint $table): void {
                $table->string('template_key', 64)->nullable();
            },
            'sent_by_user_id' => function (Blueprint $table): void {
                $table->unsignedBigInteger('sent_by_user_id')->nullable();
            },
            'sent_at' => function (Blueprint $table): void {
                $table->timestamp('sent_at')->nullable();
            },
            'status' => function (Blueprint $table): void {
                $table->string('status', 32)->default('queued');
            },
            'error' => function (Blueprint $table): void {
                $table->text('error')->nullable();
            },
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn('outgoing_email_messages', $column)) {
                continue;
            }

            Schema::table('outgoing_email_messages', function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }

        $this->ensureNamedIndex('outgoing_email_messages', 'oem_thread_id_idx', ['thread_id']);
        $this->ensureNamedIndex('outgoing_email_messages', 'oem_provider_message_id_idx', ['provider_message_id']);
        $this->ensureNamedIndex('outgoing_email_messages', 'oem_order_id_idx', ['order_id']);
        $this->ensureNamedIndex('outgoing_email_messages', 'oem_status_sent_idx', ['status', 'sent_at']);
    }

    private function ensureIncomingEmailIgnoreStatsTable(): void
    {
        if (Schema::hasTable('incoming_email_ignore_stats')) {
            return;
        }

        Schema::create('incoming_email_ignore_stats', function (Blueprint $table): void {
            $table->id();
            $table->date('stat_date');
            $table->string('reason', 255);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['stat_date', 'reason'], 'ieis_date_reason_uq');
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureNamedIndex(string $table, string $indexName, array $columns): void
    {
        if ($this->hasNamedIndex($table, $indexName) || $this->hasIndexOnColumns($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($indexName, $columns): void {
            $tableBlueprint->index($columns, $indexName);
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

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexOnColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
};
