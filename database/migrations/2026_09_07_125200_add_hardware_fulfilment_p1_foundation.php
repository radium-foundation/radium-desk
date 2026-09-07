<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            $table->json('billing_address_structured')->nullable()->after('billing_state');
            $table->json('shipping_address_structured')->nullable()->after('shipping_address');
            $table->json('parcel')->nullable()->after('shipping_address_structured');
        });

        Schema::table('commerce_order_items', function (Blueprint $table) {
            $table->string('shipping_line_kind', 40)->nullable()->after('sku');
            $table->boolean('requires_shipping')->nullable()->after('shipping_line_kind');
            $table->unsignedBigInteger('product_id')->nullable()->after('requires_shipping');
            $table->unsignedBigInteger('model_id')->nullable()->after('product_id');
            $table->string('catalog_sku', 64)->nullable()->after('model_id');
            $table->unsignedBigInteger('rdserviceid')->nullable()->after('catalog_sku');
            $table->unsignedBigInteger('amcid')->nullable()->after('rdserviceid');
            $table->unsignedBigInteger('otgid')->nullable()->after('amcid');

            $table->index('model_id');
            $table->index('shipping_line_kind');
        });

        Schema::create('hardware_fulfilments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->string('channel', 40);
            $table->string('source_type', 40);
            $table->string('source_id', 80);
            $table->string('idempotency_key', 120);
            $table->string('state', 40);
            $table->unsignedBigInteger('support_order_id')->nullable();
            $table->string('cashfree_payment_id', 128)->nullable();
            $table->string('payment_reference', 128)->nullable();
            $table->unsignedBigInteger('fulfilment_branch_id')->nullable();
            $table->string('issuer_location', 32)->nullable();
            $table->unsignedBigInteger('statutory_invoice_id')->nullable();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->string('shipment_no', 80)->nullable();
            $table->string('awb', 64)->nullable();
            $table->string('provider_shipment_id', 80)->nullable();
            $table->string('provider_awb', 80)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('serials_allocated_at')->nullable();
            $table->timestamp('invoice_issued_at')->nullable();
            $table->timestamp('shipment_created_at')->nullable();
            $table->timestamp('awb_assigned_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique('commerce_order_id');
            $table->unique('idempotency_key');
            $table->unique(['channel', 'source_type', 'source_id'], 'hardware_fulfilments_source_unique');
            $table->unique('statutory_invoice_id');
            $table->unique('shipment_id');
            $table->index('state');
            $table->index('support_order_id');
            $table->index('source_id');
            $table->index('cashfree_payment_id');
        });

        Schema::create('hardware_fulfilment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hardware_fulfilment_id')->constrained('hardware_fulfilments')->cascadeOnDelete();
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40);
            $table->string('actor_type', 32)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['hardware_fulfilment_id', 'created_at'], 'hw_fulfilment_events_fulfilment_created_idx');
        });

        Schema::create('hardware_fulfilment_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hardware_fulfilment_id')->constrained('hardware_fulfilments')->cascadeOnDelete();
            $table->foreignId('commerce_order_item_id')->nullable()->constrained('commerce_order_items')->nullOnDelete();
            $table->unsignedSmallInteger('line_no')->nullable();
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('inventory_serial_id')->nullable();
            $table->string('serial_number', 128)->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamp('allocated_at')->nullable();
            $table->timestamps();

            $table->unique(['hardware_fulfilment_id', 'commerce_order_item_id', 'position'], 'hw_fulfilment_serials_line_pos_unique');
            $table->unique('inventory_serial_id');
            $table->unique('serial_number');
            $table->index('hardware_fulfilment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardware_fulfilment_serials');
        Schema::dropIfExists('hardware_fulfilment_events');
        Schema::dropIfExists('hardware_fulfilments');

        Schema::table('commerce_order_items', function (Blueprint $table) {
            $table->dropIndex(['model_id']);
            $table->dropIndex(['shipping_line_kind']);
            $table->dropColumn([
                'shipping_line_kind',
                'requires_shipping',
                'product_id',
                'model_id',
                'catalog_sku',
                'rdserviceid',
                'amcid',
                'otgid',
            ]);
        });

        Schema::table('commerce_orders', function (Blueprint $table) {
            $table->dropColumn([
                'billing_address_structured',
                'shipping_address_structured',
                'parcel',
            ]);
        });
    }
};
