<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_order_buyer_gst_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_order_id')->constrained()->cascadeOnDelete();
            $table->string('submitted_gstin', 15);
            $table->string('verification_status', 32);
            $table->string('invoice_classification', 8)->nullable();
            $table->string('verification_source', 32)->nullable();
            $table->string('provider_correlation_id', 128)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('legal_name', 255)->nullable();
            $table->string('registration_status', 64)->nullable();
            $table->string('review_reason', 64)->nullable();
            $table->json('submitted_billing_address_structured')->nullable();
            $table->json('submitted_shipping_address_structured')->nullable();
            $table->json('verified_registration_address_structured')->nullable();
            $table->json('bill_to_address_structured')->nullable();
            $table->string('provider_payload_hash', 64)->nullable();
            $table->timestamps();

            $table->unique('commerce_order_id');
            $table->index(['submitted_gstin', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_buyer_gst_verifications');
    }
};
