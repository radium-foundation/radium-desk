<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_price_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->unsignedBigInteger('radiumbox_model_id');
            $table->decimal('requested_publish_price', 12, 2);
            $table->decimal('requested_gst_percentage', 5, 2);
            $table->string('idempotency_key', 191);
            $table->timestamp('requested_at');
            $table->timestamp('applied_at')->nullable();
            $table->string('status', 20);
            $table->text('error_summary')->nullable();
            $table->decimal('applied_publish_price', 12, 2)->nullable();
            $table->decimal('applied_selling_price', 12, 2)->nullable();
            $table->unsignedInteger('applied_liveprice')->nullable();
            $table->decimal('applied_gst_percentage', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['inventory_product_id', 'created_at']);
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_price_sync_logs');
    }
};
