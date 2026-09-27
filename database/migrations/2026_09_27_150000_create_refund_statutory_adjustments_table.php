<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_statutory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_request_id')->constrained('refund_requests')->cascadeOnDelete();
            $table->foreignId('statutory_invoice_id')->nullable()->constrained('statutory_invoices')->nullOnDelete();
            $table->string('status', 32);
            $table->string('idempotency_key', 160);
            $table->string('skip_reason', 64)->nullable();
            $table->json('orchestrator_result')->nullable();
            $table->foreignId('outbox_event_id')->nullable()->constrained('outbox_events')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('refund_request_id');
            $table->unique('idempotency_key');
            $table->index(['statutory_invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_statutory_adjustments');
    }
};
