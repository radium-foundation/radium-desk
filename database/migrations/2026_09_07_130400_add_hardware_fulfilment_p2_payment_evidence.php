<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hardware_fulfilments', 'paid_recognized_at')) {
            Schema::table('hardware_fulfilments', function (Blueprint $table) {
                $table->timestamp('paid_recognized_at')->nullable()->after('ingested_at');
            });
        }

        Schema::create('hardware_fulfilment_payment_evidence', function (Blueprint $table) {
            $table->id();
            $table->string('source_id', 80);
            $table->unsignedBigInteger('hardware_fulfilment_id')->nullable();
            $table->unsignedBigInteger('commerce_order_id')->nullable();
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
            $table->index('hardware_fulfilment_id', 'hw_pay_ev_fulfilment_idx');
            $table->index('support_order_id');
            $table->index(['verified', 'source_id']);
            $table->foreign('hardware_fulfilment_id', 'hw_pay_ev_fulfilment_fk')
                ->references('id')->on('hardware_fulfilments')->nullOnDelete();
            $table->foreign('commerce_order_id', 'hw_pay_ev_commerce_fk')
                ->references('id')->on('commerce_orders')->nullOnDelete();
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
