<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('sequence_key', 120);
            $table->string('series_code', 40);
            $table->string('document_type', 24);
            $table->string('gstin_scope', 32)->nullable();
            $table->string('financial_year', 16)->nullable();
            $table->unsignedInteger('current_value')->default(0);
            $table->timestamps();

            $table->unique('sequence_key');
        });

        Schema::create('statutory_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 64);
            $table->unsignedBigInteger('sequence_allocation_id')->nullable();
            $table->string('document_type', 24);
            $table->string('status', 24);
            $table->string('channel', 40);
            $table->string('source_type', 40);
            $table->string('source_id', 80);
            $table->string('source_order_id', 80)->nullable();
            $table->string('idempotency_key', 120);
            $table->foreignId('inventory_sale_id')->nullable()->constrained('inventory_sales')->nullOnDelete();
            $table->unsignedBigInteger('support_order_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('inventory_branches')->nullOnDelete();
            $table->string('seller_gstin', 32)->nullable();
            $table->string('seller_name')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_phone', 20)->nullable();
            $table->string('buyer_gstin', 32)->nullable();
            $table->string('billing_address')->nullable();
            $table->string('place_of_supply_state', 64)->nullable();
            $table->decimal('taxable_value', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('cgst', 12, 2)->nullable();
            $table->decimal('sgst', 12, 2)->nullable();
            $table->decimal('igst', 12, 2)->nullable();
            $table->decimal('rounding', 12, 2)->default(0);
            $table->decimal('invoice_value', 12, 2)->default(0);
            $table->string('payment_method', 64)->nullable();
            $table->string('payment_reference', 128)->nullable();
            $table->unsignedBigInteger('finance_journal_id')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique('invoice_number');
            $table->unique('idempotency_key');
            $table->unique(['channel', 'source_type', 'source_id'], 'statutory_invoices_source_unique');
            $table->unique('inventory_sale_id');
            $table->index(['status', 'issued_at']);
            $table->index('channel');
            $table->index('support_order_id');
            $table->index('finance_journal_id');
        });

        Schema::create('invoice_sequence_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('invoice_sequences');
            $table->string('allocated_number', 64);
            $table->unsignedInteger('seq_int');
            $table->foreignId('invoice_id')->nullable()->constrained('statutory_invoices')->nullOnDelete();
            $table->string('idempotency_key', 120);
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamps();

            $table->unique('allocated_number');
            $table->unique('idempotency_key');
            $table->index('sequence_id');
        });

        Schema::table('statutory_invoices', function (Blueprint $table) {
            $table->foreign('sequence_allocation_id')
                ->references('id')
                ->on('invoice_sequence_allocations')
                ->nullOnDelete();
        });

        Schema::create('statutory_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('statutory_invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->string('sku', 64)->nullable();
            $table->string('description');
            $table->string('hsn_sac', 16)->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('gst_percentage', 5, 2)->default(0);
            $table->decimal('taxable_value', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('cgst', 12, 2)->nullable();
            $table->decimal('sgst', 12, 2)->nullable();
            $table->decimal('igst', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'line_no']);
        });

        Schema::create('e_invoice_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('statutory_invoices')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('irn', 128)->nullable();
            $table->string('ack_no', 64)->nullable();
            $table->timestamp('ack_date')->nullable();
            $table->text('signed_qr')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();

            $table->unique('invoice_id');
            $table->unique('irn');
        });

        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->foreignId('statutory_invoice_id')
                ->nullable()
                ->after('invoice_number')
                ->constrained('statutory_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('statutory_invoice_id');
        });

        Schema::dropIfExists('e_invoice_records');
        Schema::dropIfExists('statutory_invoice_items');

        Schema::table('statutory_invoices', function (Blueprint $table) {
            $table->dropForeign(['sequence_allocation_id']);
        });

        Schema::dropIfExists('invoice_sequence_allocations');
        Schema::dropIfExists('statutory_invoices');
        Schema::dropIfExists('invoice_sequences');
    }
};
