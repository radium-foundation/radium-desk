<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_wallet_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('central_wallet_id');
            $table->string('caller_id', 64);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('state', 32);
            $table->string('business_reference', 191);
            $table->uuid('correlation_id');
            $table->string('source_reference', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedBigInteger('ledger_entry_id')->nullable();
            $table->timestamps();

            $table->foreign('central_wallet_id', 'cw_reservations_wallet_fk')
                ->references('id')
                ->on('central_wallets')
                ->cascadeOnDelete();

            $table->foreign('ledger_entry_id', 'cw_reservations_ledger_fk')
                ->references('id')
                ->on('central_wallet_ledger_entries')
                ->nullOnDelete();

            $table->index(['central_wallet_id', 'state'], 'cw_reservations_wallet_state_idx');
            $table->index(['state', 'expires_at'], 'cw_reservations_state_expires_idx');
            $table->index(['caller_id', 'business_reference'], 'cw_reservations_caller_business_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_wallet_reservations');
    }
};
