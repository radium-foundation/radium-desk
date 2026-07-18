<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_email_messages', function (Blueprint $table) {
            $table->id();
            $table->string('intake_channel', 32)->default('email');
            $table->string('mailbox', 255);
            $table->string('channel', 64)->nullable();
            $table->string('provider', 32)->default('fixture');
            $table->string('provider_message_id', 255)->nullable();
            $table->string('rfc_message_id', 512)->nullable();
            $table->string('thread_id', 255)->nullable();
            $table->string('from_email', 255);
            $table->string('from_name', 255)->nullable();
            $table->json('to_emails')->nullable();
            $table->string('subject', 998)->nullable();
            $table->text('preview')->nullable();
            $table->timestamp('received_at');
            $table->unsignedInteger('attachment_count')->default(0);
            $table->json('headers')->nullable();
            $table->json('labels')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('status', 32)->default('received');
            $table->string('ignore_reason', 255)->nullable();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->unique('rfc_message_id', 'iem_rfc_message_id_uq');
            $table->unique(['provider', 'provider_message_id'], 'iem_provider_message_uq');
            $table->index('from_email', 'iem_from_email_idx');
            $table->index('thread_id', 'iem_thread_id_idx');
            $table->index(['status', 'received_at'], 'iem_status_received_idx');
            $table->index('incident_id', 'iem_incident_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_email_messages');
    }
};
