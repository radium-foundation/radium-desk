<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_return_refund_allocation_opm_conversions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('return_exchange_case_id')->constrained('return_exchange_cases')->cascadeOnDelete();
            $table->foreignId('online_return_refund_allocation_id')->constrained('online_return_refund_allocations')->cascadeOnDelete();
            $table->foreignId('online_return_refund_allocation_line_id')->constrained('online_return_refund_allocation_lines')->cascadeOnDelete();
            $table->foreignId('online_return_refund_allocation_line_execution_id')
                ->constrained('online_return_refund_allocation_line_executions')
                ->cascadeOnDelete();
            $table->string('desk_wallet_reference', 128);
            $table->string('wallet_executor_provider', 64);
            $table->string('wallet_execution_reference', 128)->nullable();
            $table->string('wallet_transaction_id', 128)->nullable();
            $table->string('submit_idempotency_key', 128)->nullable()->unique();
            $table->string('status', 64)->index();
            $table->string('site_code', 64)->index();
            $table->string('wallet_credit_source', 32)->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->foreignId('requester_id')->constrained('users');
            $table->foreignId('approver_id')->nullable()->constrained('users');
            $table->foreignId('payout_operator_id')->nullable()->constrained('users');
            $table->string('site_reservation_id', 128)->nullable();
            $table->string('site_reservation_status', 64)->nullable();
            $table->string('cashfree_order_id', 64)->nullable();
            $table->string('cashfree_refund_id', 128)->nullable();
            $table->decimal('cashfree_refund_amount', 12, 2)->nullable();
            $table->string('cashfree_refund_status', 64)->nullable();
            $table->timestamp('cashfree_verified_at')->nullable();
            $table->string('wallet_finalization_idempotency_key', 128)->nullable();
            $table->string('wallet_reversal_reference', 128)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('reconciliation_entered_at')->nullable();
            $table->timestamps();

            $table->unique('cashfree_refund_id');
            $table->index(['online_return_refund_allocation_line_execution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_return_refund_allocation_opm_conversions');
    }
};
