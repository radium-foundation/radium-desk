<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_wallet_balance_migrations', function (Blueprint $table): void {
            $table->uuid('migration_operation_id')->primary();
            $table->string('migration_batch_id', 64)->nullable();
            $table->string('status', 32);
            $table->string('source_site_code', 64);
            $table->string('source_local_user_id', 64);
            $table->unsignedBigInteger('source_users_wallet_id');
            $table->string('source_order_reference', 191);
            $table->string('source_business_reference', 191);
            $table->decimal('source_amount', 12, 2);
            $table->char('source_currency', 3)->default('INR');
            $table->timestamp('source_created_at')->nullable();
            $table->uuid('destination_central_wallet_id');
            $table->unsignedBigInteger('destination_ledger_entry_id')->nullable();
            $table->string('source_retirement_reference', 191)->nullable();
            $table->string('idempotency_key', 128);
            $table->string('owner_approval_ref', 191)->nullable();
            $table->string('actor_id', 128)->nullable();
            $table->uuid('correlation_id');
            $table->json('metadata')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('central_credited_at')->nullable();
            $table->timestamp('source_retired_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('aborted_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'cw_balance_migrations_idempotency_uq');
            $table->unique(
                ['source_site_code', 'source_users_wallet_id'],
                'cw_balance_migrations_source_row_uq'
            );
            $table->index('status', 'cw_balance_migrations_status_idx');
            $table->index('destination_central_wallet_id', 'cw_balance_migrations_cwid_idx');

            $table->foreign('destination_central_wallet_id', 'cw_balance_migrations_wallet_fk')
                ->references('id')
                ->on('central_wallets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_wallet_balance_migrations');
    }
};
