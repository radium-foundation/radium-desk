<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_no', 40);
            $table->foreignId('commerce_order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->foreignId('hardware_fulfilment_id')->nullable()->constrained('hardware_fulfilments')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('status', 24);
            $table->string('invoice_number', 64)->nullable();
            $table->json('serial_numbers')->nullable();
            $table->string('pickup_location', 80)->nullable();
            $table->string('awb', 64)->nullable();
            $table->string('courier_id', 32)->nullable();
            $table->string('courier_name', 80)->nullable();
            $table->string('external_order_id', 64)->nullable();
            $table->string('external_shipment_id', 64)->nullable();
            $table->string('idempotency_key', 120);
            $table->string('correlation_id', 64);
            $table->json('create_snapshot')->nullable();
            $table->string('failure_class', 40)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('provider_accepted_at')->nullable();
            $table->timestamp('awb_assigned_at')->nullable();
            $table->timestamp('pickup_requested_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->unique('shipment_no');
            $table->unique('commerce_order_id');
            $table->unique('hardware_fulfilment_id');
            $table->unique('idempotency_key');
            $table->unique('correlation_id');
            $table->unique('awb');
            $table->unique(['provider', 'external_order_id'], 'shipments_provider_ext_order_unique');
            $table->unique(['provider', 'external_shipment_id'], 'shipments_provider_ext_shipment_unique');
            $table->index(['provider', 'status']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('source', 24);
            $table->string('activity')->nullable();
            $table->string('awb', 64)->nullable();
            $table->string('external_order_id', 64)->nullable();
            $table->string('external_shipment_id', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
    }
};
