<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_email_messages', function (Blueprint $table) {
            $table->string('classification', 64)->nullable()->after('ignore_reason');
            $table->index('classification', 'iem_classification_idx');
        });

        Schema::create('outgoing_email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('in_reply_to_incoming_email_message_id')
                ->nullable()
                ->constrained('incoming_email_messages')
                ->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
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
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 32)->default('queued');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('thread_id', 'oem_thread_id_idx');
            $table->index('provider_message_id', 'oem_provider_message_id_idx');
            $table->index('order_id', 'oem_order_id_idx');
            $table->index(['status', 'sent_at'], 'oem_status_sent_idx');
        });

        Schema::create('incoming_email_ignore_stats', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date');
            $table->string('reason', 255);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['stat_date', 'reason'], 'ieis_date_reason_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_email_ignore_stats');
        Schema::dropIfExists('outgoing_email_messages');

        Schema::table('incoming_email_messages', function (Blueprint $table) {
            $table->dropIndex('iem_classification_idx');
            $table->dropColumn('classification');
        });
    }
};
