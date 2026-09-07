<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_sku_maps', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->unsignedBigInteger('model_id');
            $table->foreignId('inventory_product_id')->constrained('inventory_products')->restrictOnDelete();
            $table->string('catalog_sku', 64)->nullable();
            $table->string('channel_sku', 64)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['channel', 'model_id'], 'channel_sku_maps_channel_model_unique');
            $table->index('inventory_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sku_maps');
    }
};
