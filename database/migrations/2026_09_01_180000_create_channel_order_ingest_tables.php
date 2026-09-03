<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 32);
            $table->string('channel', 40);
            $table->string('source_type', 40);
            $table->string('source_id', 80);
            $table->string('source_order_id', 80)->nullable();
            $table->string('idempotency_key', 120);
            $table->string('payload_hash', 64);
            $table->string('status', 24);
            $table->boolean('invoice_eligible')->default(false);
            $table->string('payment_status', 24);
            $table->string('payment_provider', 64)->nullable();
            $table->string('payment_reference', 128)->nullable();
            $table->string('payment_method', 64)->nullable();
            $table->string('currency', 8);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_email')->nullable();
            $table->string('buyer_gstin', 32)->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('seller_gstin', 32)->nullable();
            $table->string('seller_name')->nullable();
            $table->string('branch_code', 32)->nullable();
            $table->string('place_of_supply_state', 64)->nullable();
            $table->decimal('taxable_value', 12, 2)->nullable();
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('tax_total', 12, 2)->nullable();
            $table->decimal('order_value', 12, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('received_at');
            $table->string('status_reason')->nullable();
            $table->foreignId('statutory_invoice_id')->nullable()->constrained('statutory_invoices')->nullOnDelete();
            $table->unsignedBigInteger('support_order_id')->nullable();
            $table->timestamps();

            $table->unique('order_no');
            $table->unique('idempotency_key');
            $table->unique(['channel', 'source_type', 'source_id'], 'commerce_orders_source_unique');
            $table->unique('statutory_invoice_id');
            $table->index(['channel', 'status']);
            $table->index('payment_reference');
            $table->index('support_order_id');
        });

        Schema::create('commerce_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_order_id')->constrained('commerce_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->string('sku', 64)->nullable();
            $table->string('variant', 64)->nullable();
            $table->string('description');
            $table->string('hsn_sac', 16)->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('gst_percentage', 5, 2)->nullable();
            $table->decimal('taxable_value', 12, 2)->nullable();
            $table->decimal('tax_total', 12, 2)->nullable();
            $table->decimal('cgst', 12, 2)->nullable();
            $table->decimal('sgst', 12, 2)->nullable();
            $table->decimal('igst', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->nullable();
            $table->timestamps();

            $table->index(['commerce_order_id', 'line_no']);
        });

        Schema::create('channel_ingest_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 40)->nullable();
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 80)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('outcome', 24);
            $table->unsignedSmallInteger('http_status');
            $table->boolean('signature_ok')->default(false);
            $table->foreignId('commerce_order_id')->nullable()->constrained('commerce_orders')->nullOnDelete();
            $table->unsignedBigInteger('statutory_invoice_id')->nullable();
            $table->string('invoice_number', 64)->nullable();
            $table->string('error', 255)->nullable();
            $table->string('remote_ip', 45)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['channel', 'source_id']);
            $table->index('outcome');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_ingest_attempts');
        Schema::dropIfExists('commerce_order_items');
        Schema::dropIfExists('commerce_orders');
    }
};
