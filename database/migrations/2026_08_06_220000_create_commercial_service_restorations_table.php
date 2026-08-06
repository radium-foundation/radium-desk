<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_service_restorations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('refund_request_id')->constrained('refund_requests')->cascadeOnDelete();
            $table->boolean('finance_verified')->default(false);
            $table->boolean('wallet_reversed_externally')->default(false);
            $table->string('wallet_reversal_reference')->nullable();
            $table->text('finance_note')->nullable();
            $table->foreignId('verified_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'refund_request_id', 'revoked_at'], 'csr_order_refund_active_idx');
            $table->index('refund_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_service_restorations');
    }
};
