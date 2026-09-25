<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_statutory_gst_mismatch_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_order_id')->constrained('commerce_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('support_order_id')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->string('status', 40);
            $table->string('validation_reason', 80);
            $table->string('original_buyer_gstin', 32)->nullable();
            $table->string('original_billing_state', 64)->nullable();
            $table->string('original_place_of_supply_state', 64)->nullable();
            $table->string('original_billing_pincode', 16)->nullable();
            $table->json('original_billing_address_structured')->nullable();
            $table->text('original_billing_address')->nullable();
            $table->string('corrected_buyer_gstin', 32)->nullable();
            $table->string('corrected_billing_state', 64)->nullable();
            $table->string('corrected_place_of_supply_state', 64)->nullable();
            $table->string('corrected_billing_pincode', 16)->nullable();
            $table->json('corrected_billing_address_structured')->nullable();
            $table->string('customer_email')->nullable();
            $table->timestamp('customer_email_sent_at')->nullable();
            $table->timestamp('customer_response_at')->nullable();
            $table->timestamp('response_deadline_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_classification', 40)->nullable();
            $table->string('fallback_reason')->nullable();
            $table->timestamp('fallback_at')->nullable();
            $table->foreignId('statutory_invoice_id')->nullable()->constrained('statutory_invoices')->nullOnDelete();
            $table->foreignId('corrected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->unique('commerce_order_id', 'svc_gst_mismatch_commerce_unique');
            $table->index('status');
            $table->index('response_deadline_at');
            $table->index('support_order_id');
            $table->index('incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_statutory_gst_mismatch_exceptions');
    }
};
