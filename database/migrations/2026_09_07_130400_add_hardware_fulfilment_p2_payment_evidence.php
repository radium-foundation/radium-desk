<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->timestamp('paid_recognized_at')->nullable()->after('ingested_at');
        });

        Schema::create('hardware_fulfilment_payment_evidence', function (Blueprint $table) {
            $table->id();
            $table->string('source_id', 80);
            $table->foreignId('hardware_fulfilment_id')->nullable()->constrained('hardware_fulfilments')->nullOnDelete();
            $table->foreignId('commerce_order_id')->nullable()->constrained('commerce_orders')->nullOnDelete();
            $table->unsignedBigInteger('support_order_id')->nullable();
            $table->string('cashfree_payment_id', 128);
            $table->string('merchant_order_id', 80)->nullable();
            $table->string('cf_order_id', 80)->nullable();
            $table->string('gateway_order_id', 128)->nullable();
            $table->string('gateway_payment_id', 128)->nullable();
            $table->string('bank_reference', 100)->nullable();
            $table->string('payment_status', 32);
            $table->boolean('verified')->default(false);
            $table->decimal('payment_amount', 12, 2)->nullable();
            $table->string('payment_method', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('cashfree_webhook_log_id')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('cashfree_payment_id', 'hw_payment_evidence_cf_payment_unique');
            $table->unique(['source_id', 'cashfree_payment_id'], 'hw_payment_evidence_source_cf_unique');
            $table->index('source_id');
            $table->index('hardware_fulfilment_id');
            $table->index('support_order_id');
            $table->index(['verified', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardware_fulfilment_payment_evidence');

        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->dropColumn('paid_recognized_at');
        });
    }
};
