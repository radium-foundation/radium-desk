<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('legacy_admin_attribute_id')->nullable();
            $table->timestamps();

            $table->unique('code');
            $table->unique('legacy_admin_attribute_id');
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('service_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('service_categories');
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('duration_label', 120)->nullable();
            $table->string('sac_code', 16)->nullable();
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('price_ex_gst', 12, 2)->default(0);
            $table->decimal('price_incl_gst', 12, 2)->nullable();
            $table->foreignId('parent_device_model_id')->nullable()->constrained('device_models')->nullOnDelete();
            $table->unsignedBigInteger('legacy_admin_product_id')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code');
            $table->unique('legacy_admin_product_id');
            $table->index(['category_id', 'is_active']);
        });

        Schema::create('service_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_number', 40);
            $table->string('status', 24);
            $table->foreignId('customer_id')->constrained('inventory_customers');
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->string('buyer_name');
            $table->string('buyer_phone', 20);
            $table->string('buyer_email')->nullable();
            $table->string('buyer_gstin', 32)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('billing_state', 64)->nullable();
            $table->string('place_of_supply_state', 64)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamp('valid_until')->nullable();
            $table->unsignedBigInteger('converted_service_order_id')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('quote_number');
            $table->unique('idempotency_key');
            $table->index(['status', 'created_at']);
        });

        Schema::create('service_quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('service_quotes')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('service_item_id')->nullable()->constrained('service_items')->nullOnDelete();
            $table->string('description');
            $table->string('sac_code', 16)->nullable();
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price_ex_gst', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('taxable_value', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['quote_id', 'line_no']);
        });

        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 40);
            $table->foreignId('quote_id')->nullable()->unique()->constrained('service_quotes')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('inventory_customers');
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->string('buyer_name');
            $table->string('buyer_phone', 20);
            $table->string('buyer_email')->nullable();
            $table->string('buyer_gstin', 32)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('billing_state', 64)->nullable();
            $table->string('place_of_supply_state', 64)->nullable();
            $table->string('status', 24);
            $table->string('payment_status', 24)->default('unpaid');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->foreignId('statutory_invoice_id')->nullable()->constrained('statutory_invoices')->nullOnDelete();
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamps();

            $table->unique('order_number');
            $table->unique('statutory_invoice_id');
            $table->unique('idempotency_key');
            $table->index(['status', 'payment_status']);
        });

        Schema::table('service_quotes', function (Blueprint $table) {
            $table->foreign('converted_service_order_id')
                ->references('id')
                ->on('service_orders')
                ->nullOnDelete();
            $table->unique('converted_service_order_id');
        });

        Schema::create('service_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('service_item_id')->nullable()->constrained('service_items')->nullOnDelete();
            $table->string('description');
            $table->string('sac_code', 16)->nullable();
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price_ex_gst', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('taxable_value', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['service_order_id', 'line_no']);
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 40);
            $table->foreignId('customer_id')->constrained('inventory_customers');
            $table->decimal('amount', 12, 2);
            $table->string('method', 64);
            $table->string('reference', 128)->nullable();
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamps();

            $table->unique('payment_number');
            $table->unique('idempotency_key');
            $table->index(['customer_id', 'payment_date']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained('customer_payments')->cascadeOnDelete();
            $table->foreignId('statutory_invoice_id')->constrained('statutory_invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index('statutory_invoice_id');
            $table->index(['customer_payment_id', 'statutory_invoice_id'], 'payment_alloc_payment_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('service_order_lines');

        Schema::table('service_quotes', function (Blueprint $table) {
            $table->dropForeign(['converted_service_order_id']);
            $table->dropUnique(['converted_service_order_id']);
            $table->dropColumn('converted_service_order_id');
        });

        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('service_quote_lines');
        Schema::dropIfExists('service_quotes');
        Schema::dropIfExists('service_items');
        Schema::dropIfExists('service_categories');
    }
};
