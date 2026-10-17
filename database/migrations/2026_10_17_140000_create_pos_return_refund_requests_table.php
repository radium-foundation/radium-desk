<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_return_refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_exchange_case_id')->constrained('return_exchange_cases')->cascadeOnDelete();
            $table->foreignId('inventory_sale_id')->constrained('inventory_sales')->cascadeOnDelete();
            $table->string('reference_no', 32)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 64);
            $table->string('payment_reference', 128)->nullable();
            $table->string('status', 40);
            $table->foreignId('statutory_invoice_id')->nullable()->constrained('statutory_invoices')->nullOnDelete();
            $table->string('tax_evidence_type', 32);
            $table->text('requester_remarks')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();

            $table->unique('return_exchange_case_id', 'pos_return_refund_case_unique');
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_return_refund_requests');
    }
};
