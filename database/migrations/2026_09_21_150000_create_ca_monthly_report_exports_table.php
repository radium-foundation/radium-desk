<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ca_monthly_report_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('format', 10);
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('row_count')->nullable();
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->text('failure_message')->nullable();
            $table->string('idempotency_key', 64);
            $table->string('email_recipient')->nullable();
            $table->string('email_status', 20)->nullable();
            $table->string('email_delivery_mode', 20)->nullable();
            $table->text('email_failure_message')->nullable();
            $table->timestamp('email_queued_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'idempotency_key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ca_monthly_report_exports');
    }
};
