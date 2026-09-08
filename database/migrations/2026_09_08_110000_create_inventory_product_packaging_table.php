<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_product_packaging', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->decimal('gross_weight', 8, 3);
            $table->decimal('length', 8, 2);
            $table->decimal('breadth', 8, 2);
            $table->decimal('height', 8, 2);
            $table->string('weight_unit', 8);
            $table->string('dimension_unit', 8);
            $table->foreignId('verified_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique('inventory_product_id', 'inventory_product_packaging_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_product_packaging');
    }
};
