<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_wallet_refund_migrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('batch_id', 64);
            $table->unsignedBigInteger('refund_id');
            $table->string('refund_reference', 64);
            $table->decimal('amount', 12, 2);
            $table->string('source_type', 32);
            $table->string('source_application', 64)->nullable();
            $table->unsignedBigInteger('source_wallet_id')->nullable();
            $table->string('source_reference', 191);
            $table->uuid('desk_customer_id')->nullable();
            $table->uuid('cwid')->nullable();
            $table->string('lane', 64);
            $table->string('status', 32);
            $table->string('idempotency_key', 128);
            $table->string('owner_approval_ref', 191)->nullable();
            $table->unsignedBigInteger('destination_ledger_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_ledger_entry_id')->nullable();
            $table->string('source_debit_reference', 191)->nullable();
            $table->uuid('balance_migration_operation_id')->nullable();
            $table->string('order_number', 64)->nullable();
            $table->string('identity_class', 8)->nullable();
            $table->json('metadata')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique('refund_id', 'cw_refund_migrations_refund_uq');
            $table->unique('idempotency_key', 'cw_refund_migrations_idempotency_uq');
            $table->index('batch_id', 'cw_refund_migrations_batch_idx');
            $table->index('status', 'cw_refund_migrations_status_idx');
            $table->index('lane', 'cw_refund_migrations_lane_idx');
            $table->index('cwid', 'cw_refund_migrations_cwid_idx');

            $table->foreign('desk_customer_id', 'cw_refund_migrations_customer_fk')
                ->references('id')
                ->on('central_customers')
                ->nullOnDelete();

            $table->foreign('cwid', 'cw_refund_migrations_wallet_fk')
                ->references('id')
                ->on('central_wallets')
                ->nullOnDelete();

            $table->foreign('destination_ledger_entry_id', 'cw_refund_migrations_ledger_fk')
                ->references('id')
                ->on('central_wallet_ledger_entries')
                ->nullOnDelete();

            $table->foreign('reversal_ledger_entry_id', 'cw_refund_migrations_reversal_fk')
                ->references('id')
                ->on('central_wallet_ledger_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_wallet_refund_migrations');
    }
};
