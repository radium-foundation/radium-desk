<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->string('source', 40)->default('finance_receipt')->after('idempotency_key');
            $table->index('source');
        });

        Schema::create('statutory_invoice_payment_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statutory_invoice_id')->constrained('statutory_invoices')->cascadeOnDelete();
            $table->string('outcome', 32);
            $table->decimal('verified_amount', 12, 2)->default(0);
            $table->string('payment_method', 64)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_branch', 120)->nullable();
            $table->string('reference', 128)->nullable();
            $table->text('verification_remark')->nullable();
            $table->string('source', 40)->default('historical_pos_backfill');
            $table->foreignId('customer_payment_id')->nullable()->constrained('customer_payments')->nullOnDelete();
            $table->foreignId('payment_allocation_id')->nullable()->constrained('payment_allocations')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120);
            $table->timestamp('completed_at');
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique('statutory_invoice_id');
            $table->unique('idempotency_key');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_invoice_payment_reconciliations');

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
