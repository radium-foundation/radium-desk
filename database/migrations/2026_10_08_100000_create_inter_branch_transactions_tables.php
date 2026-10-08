<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inter_branch_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_no', 40)->unique();
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('from_branch_id')->constrained('inventory_branches');
            $table->foreignId('to_branch_id')->constrained('inventory_branches');
            $table->string('status', 32);
            $table->string('destination_gstin', 32)->nullable();
            $table->foreignId('statutory_invoice_id')->nullable()->constrained('statutory_invoices')->nullOnDelete();
            $table->foreignId('inventory_transfer_id')->nullable()->constrained('inventory_transfers')->nullOnDelete();
            $table->foreignId('inventory_reservation_id')->nullable()->constrained('inventory_reservations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('transporter', 200)->nullable();
            $table->string('transport_reference', 200)->nullable();
            $table->date('dispatch_date')->nullable();
            $table->string('eway_bill_reference', 100)->nullable();
            $table->string('eway_bill_status', 32)->default('not_applicable');
            $table->text('eway_bill_notes')->nullable();
            $table->timestamps();

            $table->index(['from_branch_id', 'to_branch_id', 'status']);
        });

        Schema::create('inter_branch_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inter_branch_transaction_id')->constrained('inter_branch_transactions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('gst_percentage', 5, 2);
            $table->timestamps();

            $table->index(['inter_branch_transaction_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_branch_transaction_lines');
        Schema::dropIfExists('inter_branch_transactions');
    }
};
