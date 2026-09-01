<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->string('name');
            $table->string('gstin', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('invoice_sequence')->default(0);
            $table->timestamps();

            $table->unique('code');
        });

        Schema::create('inventory_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64);
            $table->string('name');
            $table->string('hsn_code', 16)->nullable();
            $table->decimal('gst_percentage', 5, 2)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->boolean('is_serialized')->default(true);
            $table->boolean('tracks_batch')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('device_model_id')->nullable()->constrained('device_models')->nullOnDelete();
            $table->timestamps();

            $table->unique('sku');
            $table->index(['is_active', 'is_serialized']);
        });

        Schema::create('inventory_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('name');
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('sku');
            $table->index(['product_id', 'is_active']);
        });

        Schema::create('inventory_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 20);
            $table->string('email')->nullable();
            $table->string('gstin', 32)->nullable();
            $table->timestamps();

            $table->unique('phone');
        });

        Schema::create('inventory_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->string('serial_number', 128);
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->string('status', 24);
            $table->string('batch_code', 64)->nullable();
            $table->unsignedBigInteger('reserved_reservation_id')->nullable();
            $table->timestamps();

            $table->unique('serial_number');
            $table->index(['branch_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index('reserved_reservation_id');
        });

        Schema::create('inventory_stock_balances', function (Blueprint $table) {
            $table->id();
            $table->string('balance_key', 64);
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->unsignedInteger('available_qty')->default(0);
            $table->unsignedInteger('reserved_qty')->default(0);
            $table->timestamps();

            $table->unique('balance_key');
            $table->index(['product_id', 'branch_id']);
        });

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 40);
            $table->foreignId('from_branch_id')->constrained('inventory_branches');
            $table->foreignId('to_branch_id')->constrained('inventory_branches');
            $table->string('status', 24);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('transfer_no');
            $table->index(['from_branch_id', 'to_branch_id', 'status']);
        });

        Schema::create('inventory_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('inventory_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->timestamps();
        });

        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 40);
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->string('reason', 40);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('adjustment_no');
        });

        Schema::create('inventory_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('inventory_adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->integer('qty_delta');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_no', 40);
            $table->string('invoice_number', 40)->nullable();
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->foreignId('customer_id')->nullable()->constrained('inventory_customers')->nullOnDelete();
            $table->unsignedBigInteger('support_order_id')->nullable();
            $table->string('status', 24);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('payment_method', 64)->nullable();
            $table->string('payment_reference', 128)->nullable();
            $table->string('finance_handoff_status', 24)->default('pending');
            $table->unsignedBigInteger('finance_journal_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('sale_no');
            $table->unique('invoice_number');
            $table->index(['branch_id', 'status']);
            $table->index('support_order_id');
            $table->index('finance_journal_id');
        });

        Schema::create('inventory_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('inventory_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->unsignedInteger('qty');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('gst_percentage', 5, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_sale_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('inventory_sales')->cascadeOnDelete();
            $table->foreignId('sale_line_id')->constrained('inventory_sale_lines')->cascadeOnDelete();
            $table->foreignId('serial_id')->constrained('inventory_serials');
            $table->timestamps();

            $table->unique(['sale_id', 'serial_id']);
            $table->index('serial_id');
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_no', 40);
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->foreignId('sale_id')->nullable()->constrained('inventory_sales')->nullOnDelete();
            $table->string('status', 24);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique('reservation_no');
            $table->index(['branch_id', 'status']);
        });

        Schema::create('inventory_reservation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('inventory_reservations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->timestamps();
        });

        Schema::table('inventory_serials', function (Blueprint $table) {
            $table->foreign('reserved_reservation_id')
                ->references('id')
                ->on('inventory_reservations')
                ->nullOnDelete();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('type', 32);
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->foreignId('from_branch_id')->nullable()->constrained('inventory_branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('inventory_branches')->nullOnDelete();
            $table->integer('qty');
            $table->foreignId('sale_id')->nullable()->constrained('inventory_sales')->nullOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('inventory_transfers')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('inventory_reservations')->nullOnDelete();
            $table->foreignId('adjustment_id')->nullable()->constrained('inventory_adjustments')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'branch_id', 'occurred_at']);
            $table->index(['serial_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');

        Schema::table('inventory_serials', function (Blueprint $table) {
            $table->dropForeign(['reserved_reservation_id']);
        });

        Schema::dropIfExists('inventory_reservation_lines');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_sale_serials');
        Schema::dropIfExists('inventory_sale_lines');
        Schema::dropIfExists('inventory_sales');
        Schema::dropIfExists('inventory_adjustment_lines');
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('inventory_transfer_lines');
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('inventory_stock_balances');
        Schema::dropIfExists('inventory_serials');
        Schema::dropIfExists('inventory_customers');
        Schema::dropIfExists('inventory_product_variants');
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('inventory_branches');
    }
};
