<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('vendor_code', 32)->nullable()->unique();
            $table->string('business_name');
            $table->string('legal_name')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country', 64)->default('India');
            $table->string('pin', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->string('legacy_source_database')->nullable();
            $table->string('legacy_source_table')->nullable();
            $table->unsignedBigInteger('legacy_supplier_id')->nullable();
            $table->string('legacy_import_batch')->nullable();
            $table->timestamp('legacy_imported_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['legacy_source_database', 'legacy_source_table', 'legacy_supplier_id'],
                'vendors_legacy_provenance_unique',
            );
            $table->index(['gstin']);
            $table->index(['pan']);
            $table->index(['is_active']);
        });

        Schema::create('vendor_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_reference')->unique();
            $table->string('source_database');
            $table->string('source_table');
            $table->unsignedInteger('records_attempted')->default(0);
            $table->unsignedInteger('records_imported')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('records_conflicted')->default(0);
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_import_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_import_batch_id')->constrained('vendor_import_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_supplier_id');
            $table->foreignId('existing_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('conflict_reason');
            $table->json('legacy_payload');
            $table->string('resolution_status')->default('pending_review');
            $table->timestamps();

            $table->unique(
                ['vendor_import_batch_id', 'legacy_supplier_id'],
                'vendor_import_conflicts_batch_legacy_unique',
            );
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->date('po_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('status', 32);
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'po_date']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->string('sku');
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->index(['purchase_order_id', 'product_id'], 'po_items_po_product_idx');
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->date('receipt_date');
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('branch_id')->constrained('inventory_branches');
            $table->string('status', 32);
            $table->string('supplier_challan_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('completion_idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['purchase_order_id', 'status']);
            $table->index(['vendor_id', 'receipt_date']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items');
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->unsignedInteger('quantity_received');
            $table->unsignedInteger('quantity_damaged')->default(0);
            $table->unsignedInteger('quantity_short')->default(0);
            $table->timestamps();

            $table->index(['goods_receipt_id', 'purchase_order_item_id'], 'gr_items_receipt_po_item_idx');
        });

        Schema::create('goods_receipt_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('goods_receipt_item_id')->constrained('goods_receipt_items')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('product_id')->constrained('inventory_products');
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->string('serial_number');
            $table->string('validation_status', 32)->default('pending');
            $table->text('validation_message')->nullable();
            $table->foreignId('inventory_serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->timestamps();

            $table->unique(['goods_receipt_id', 'serial_number'], 'goods_receipt_serials_receipt_serial_unique');
            $table->index(['serial_number']);
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
            $table->string('supplier_invoice_number');
            $table->date('invoice_date');
            $table->decimal('invoice_amount', 14, 2);
            $table->decimal('taxable_amount', 14, 2)->nullable();
            $table->decimal('cgst_amount', 14, 2)->nullable();
            $table->decimal('sgst_amount', 14, 2)->nullable();
            $table->decimal('igst_amount', 14, 2)->nullable();
            $table->string('payment_status', 32)->default('unpaid');
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vendor_id', 'supplier_invoice_number'], 'supplier_invoices_vendor_number_unique');
            $table->index(['purchase_order_id']);
            $table->index(['payment_status']);
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method');
            $table->string('transaction_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_invoice_id', 'payment_date']);
        });

        Schema::create('purchasing_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 32);
            $table->string('related_type');
            $table->unsignedBigInteger('related_id');
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['related_type', 'related_id'], 'purch_docs_related_idx');
            $table->index(['document_type']);
        });

        Schema::create('purchasing_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 64);
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'purch_audit_auditable_idx');
            $table->index(['event']);
        });

        Schema::table('inventory_serials', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('batch_code')->constrained('vendors')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->after('vendor_id')->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->after('purchase_order_id')->constrained('goods_receipts')->nullOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('goods_receipt_id')->nullable()->after('opening_import_batch_id')->constrained('goods_receipts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_receipt_id');
        });

        Schema::table('inventory_serials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_receipt_id');
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropConstrainedForeignId('vendor_id');
        });

        Schema::dropIfExists('purchasing_audit_logs');
        Schema::dropIfExists('purchasing_documents');
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('goods_receipt_serials');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('vendor_import_conflicts');
        Schema::dropIfExists('vendor_import_batches');
        Schema::dropIfExists('vendors');
    }
};
