<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_shipment_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('commerce_order_id')->nullable()->constrained('commerce_orders')->nullOnDelete();
            $table->foreignId('hardware_fulfilment_id')->nullable()->constrained('hardware_fulfilments')->nullOnDelete();
            $table->string('source_id')->index();
            $table->string('order_family', 8);
            $table->string('phase_scope', 32)->default('phase2_ab');
            $table->string('status', 48)->index();
            $table->string('sub_status')->nullable();
            $table->string('blocker_reason', 64)->nullable();
            $table->text('cancellation_reason');
            $table->foreignId('submitted_by_user_id')->constrained('users');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finance_completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('shipment_verification', 32)->default('confirmed_unshipped');
            $table->decimal('order_total_amount', 12, 2);
            $table->decimal('wallet_tender_amount', 12, 2)->nullable();
            $table->decimal('external_tender_amount', 12, 2)->nullable();
            $table->decimal('already_refunded_amount', 12, 2)->default(0);
            $table->decimal('maximum_refundable_amount', 12, 2);
            $table->decimal('requested_refund_amount', 12, 2);
            $table->boolean('split_tender')->default(false);
            $table->boolean('execution_hold')->default(false);
            $table->text('execution_hold_reason')->nullable();
            $table->foreignId('refund_request_id')->nullable()->constrained('refund_requests')->nullOnDelete();
            $table->foreignId('recorded_tax_statutory_invoice_id')->nullable()->constrained('statutory_invoices')->nullOnDelete();
            $table->string('recorded_tax_outcome_type', 32)->nullable();
            $table->foreignId('service_case_incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->json('service_case_outcome')->nullable();
            $table->string('service_case_sync_status', 32)->default('pending');
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('ingestion_block_version')->default(1);
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->string('future_spoke_sync_status')->nullable();
            $table->json('future_spoke_sync_payload')->nullable();
            $table->timestamps();

            $table->index(['commerce_order_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_shipment_cancellation_requests');
    }
};
