<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_return_refund_allocation_line_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('online_return_refund_allocation_line_id')
                ->constrained('online_return_refund_allocation_lines', indexName: 'ora_line_exec_line_fk')
                ->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('executor_provider', 64)->nullable();
            $table->string('idempotency_key', 128);
            $table->string('execution_reference', 128)->nullable();
            $table->string('transaction_id', 128)->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('held_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('executed_by')->constrained('users', indexName: 'ora_line_exec_user_fk')->cascadeOnDelete();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->unique('idempotency_key', 'ora_line_exec_idempotency_unique');
            $table->index(['online_return_refund_allocation_line_id', 'status'], 'ora_line_exec_line_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_return_refund_allocation_line_executions');
    }
};
