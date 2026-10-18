<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_return_refund_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_exchange_case_id')->constrained('return_exchange_cases')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('hardware_fulfilment_id')->nullable()->constrained('hardware_fulfilments')->nullOnDelete();
            $table->string('reference_no', 32)->unique();
            $table->string('status', 40);
            $table->decimal('returned_line_cap', 12, 2);
            $table->decimal('order_paid_total', 12, 2);
            $table->decimal('prior_refunds_total', 12, 2);
            $table->decimal('max_allocatable_amount', 12, 2);
            $table->decimal('requested_total', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->text('requester_remarks')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->unique('return_exchange_case_id', 'online_return_refund_allocation_case_unique');
            $table->unique('idempotency_key', 'online_return_refund_allocation_idempotency_unique');
            $table->index(['status', 'requested_at']);
        });

        Schema::create('online_return_refund_allocation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('online_return_refund_allocation_id')
                ->constrained('online_return_refund_allocations', indexName: 'ora_lines_allocation_fk')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('destination', 32);
            $table->decimal('amount', 12, 2);
            $table->string('execution_reference', 128)->nullable();
            $table->timestamps();

            $table->unique(['online_return_refund_allocation_id', 'line_no'], 'ora_lines_line_no_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_return_refund_allocation_lines');
        Schema::dropIfExists('online_return_refund_allocations');
    }
};
