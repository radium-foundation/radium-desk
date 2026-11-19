<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_order_buyer_gst_b2c_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_order_buyer_gst_verification_id')->nullable()
                ->constrained('commerce_order_buyer_gst_verifications')->nullOnDelete();
            $table->string('submitted_gstin', 15);
            $table->string('verification_status', 32);
            $table->string('registration_status', 64)->nullable();
            $table->string('review_reason', 64)->nullable();
            $table->string('provider_payload_hash', 64)->nullable();
            $table->string('status', 24);
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('invoice_remark');
            $table->timestamps();

            $table->unique('commerce_order_id');
            $table->index(['status', 'authorized_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_buyer_gst_b2c_conversions');
    }
};
