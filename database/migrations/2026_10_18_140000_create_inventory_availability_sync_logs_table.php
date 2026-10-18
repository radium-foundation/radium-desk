<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_availability_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->unsignedBigInteger('radiumbox_model_id');
            $table->unsignedInteger('available_qty');
            $table->string('inventory_tracking', 32);
            $table->string('idempotency_key', 191);
            $table->timestamp('requested_at');
            $table->timestamp('applied_at')->nullable();
            $table->string('status', 20);
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['inventory_product_id', 'created_at']);
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_availability_sync_logs');
    }
};
