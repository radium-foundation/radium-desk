<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_cashfree_payment_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('business_order_id', 64)->index();
            $table->string('legacy_source', 64)->nullable();
            $table->string('state', 64)->index();
            $table->string('discovery_classification', 64)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->string('cf_payment_id', 64)->nullable();
            $table->unsignedBigInteger('finance_journal_id')->nullable();
            $table->json('audit_meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_cashfree_payment_reconciliations');
    }
};
