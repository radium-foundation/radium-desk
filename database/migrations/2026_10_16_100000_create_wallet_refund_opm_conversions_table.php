<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_refund_opm_conversions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('refund_request_id')->constrained('refund_requests')->cascadeOnDelete();
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
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('reconciliation_entered_at')->nullable();
            $table->timestamps();

            $table->unique('cashfree_refund_id');
            $table->index(['refund_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_refund_opm_conversions');
    }
};
