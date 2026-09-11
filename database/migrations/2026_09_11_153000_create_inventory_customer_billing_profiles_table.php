<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_customer_billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('inventory_customers')->cascadeOnDelete();
            $table->text('line1')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('pincode', 16)->nullable();
            $table->string('source', 64);
            $table->unsignedBigInteger('source_legacy_order_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_customer_billing_profiles');
    }
};
