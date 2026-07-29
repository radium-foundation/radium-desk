<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_mailbox_sync_states', function (Blueprint $table) {
            $table->timestamp('last_attempted_at')->nullable()->after('last_synced_at');
            $table->string('profile_history_id', 64)->nullable()->after('history_id');
            $table->unsignedInteger('messages_processed_last_run')->default(0)->after('last_error');
            $table->unsignedInteger('messages_skipped_last_run')->default(0)->after('messages_processed_last_run');
            $table->unsignedInteger('messages_retried_last_run')->default(0)->after('messages_skipped_last_run');
            $table->unsignedInteger('messages_failed_last_run')->default(0)->after('messages_retried_last_run');
            $table->unsignedInteger('history_pages_last_run')->default(0)->after('messages_failed_last_run');
            $table->unsignedInteger('cursor_advances_last_run')->default(0)->after('history_pages_last_run');
            $table->unsignedInteger('last_sync_duration_ms')->nullable()->after('cursor_advances_last_run');
            $table->unsignedInteger('last_response_latency_ms')->nullable()->after('last_sync_duration_ms');
            $table->string('oauth_status', 32)->nullable()->after('last_response_latency_ms');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('oauth_status');
        });

        Schema::create('gmail_sync_message_failures', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox', 255);
            $table->string('message_id', 128);
            $table->string('endpoint', 255)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('error_payload')->nullable();
            $table->string('history_id', 64)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(1);
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->string('request_id', 128)->nullable();
            $table->timestamps();

            $table->index(['mailbox', 'created_at']);
            $table->index(['message_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_sync_message_failures');

        Schema::table('gmail_mailbox_sync_states', function (Blueprint $table) {
            $table->dropColumn([
                'last_attempted_at',
                'profile_history_id',
                'messages_processed_last_run',
                'messages_skipped_last_run',
                'messages_retried_last_run',
                'messages_failed_last_run',
                'history_pages_last_run',
                'cursor_advances_last_run',
                'last_sync_duration_ms',
                'last_response_latency_ms',
                'oauth_status',
                'consecutive_failures',
            ]);
        });
    }
};
