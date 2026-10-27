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
            $table->uuid('public_id')->unique('ora_opm_public_id_unique');
            $table->foreignId('return_exchange_case_id')->constrained('return_exchange_cases', indexName: 'ora_opm_case_fk')->cascadeOnDelete();
            $table->foreignId('online_return_refund_allocation_id')->constrained('online_return_refund_allocations', indexName: 'ora_opm_alloc_fk')->cascadeOnDelete();
            $table->foreignId('online_return_refund_allocation_line_id')->constrained('online_return_refund_allocation_lines', indexName: 'ora_opm_line_fk')->cascadeOnDelete();
            $table->foreignId('online_return_refund_allocation_line_execution_id')
                ->constrained('online_return_refund_allocation_line_executions', indexName: 'ora_opm_exec_fk')
                ->cascadeOnDelete();
            $table->string('desk_wallet_reference', 128);
            $table->string('wallet_executor_provider', 64);
            $table->string('wallet_execution_reference', 128)->nullable();
            $table->string('wallet_transaction_id', 128)->nullable();
            $table->string('submit_idempotency_key', 128)->nullable()->unique('ora_opm_submit_idem_unique');
            $table->string('status', 64)->index('ora_opm_status_idx');
            $table->string('site_code', 64)->index('ora_opm_site_code_idx');
            $table->string('wallet_credit_source', 32)->index('ora_opm_wallet_src_idx');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->foreignId('requester_id')->constrained('users', indexName: 'ora_opm_requester_fk');
            $table->foreignId('approver_id')->nullable()->constrained('users', indexName: 'ora_opm_approver_fk');
            $table->foreignId('payout_operator_id')->nullable()->constrained('users', indexName: 'ora_opm_payout_op_fk');
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

            $table->unique('cashfree_refund_id', 'ora_opm_cf_refund_unique');
            $table->index(['online_return_refund_allocation_line_execution_id', 'status'], 'ora_opm_exec_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_return_refund_allocation_opm_conversions');
    }
};
