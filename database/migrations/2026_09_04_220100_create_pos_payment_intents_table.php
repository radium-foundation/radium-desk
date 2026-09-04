<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payment_intents', function (Blueprint $table) {
            $table->id();
            $table->string('public_ref', 32);
            $table->string('tr', 64);
            $table->string('sale_idempotency_key', 80);
            $table->string('status', 24);
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->foreignId('receiving_bank_account_id')->constrained('finance_bank_accounts');
            $table->foreignId('upi_profile_id')->constrained('finance_bank_account_upi_profiles');
            $table->string('vpa_snapshot', 128);
            $table->string('payee_name_snapshot', 160);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('upi_uri', 512);
            $table->json('cart_payload');
            $table->string('customer_name', 160);
            $table->string('customer_phone', 20);
            $table->foreignId('reservation_id')->nullable()->constrained('inventory_reservations')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('utr', 64)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('bank_checked_at')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained('inventory_sales')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->string('abandon_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique('public_ref');
            $table->unique('tr');
            $table->unique('sale_idempotency_key');
            $table->unique('utr');
            $table->unique('sale_id');
            $table->index(['status', 'created_at']);
            $table->index(['branch_id', 'status']);
            $table->index(['receiving_bank_account_id', 'created_at'], 'pos_upi_intents_bank_created_idx');
            $table->index(['customer_phone', 'created_at'], 'pos_upi_intents_phone_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payment_intents');
    }
};
