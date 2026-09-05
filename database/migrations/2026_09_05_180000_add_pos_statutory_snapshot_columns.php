<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->string('buyer_gstin', 32)->nullable()->after('customer_id');
            $table->text('billing_address')->nullable()->after('buyer_gstin');
            $table->string('place_of_supply_state', 64)->nullable()->after('billing_address');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->dropColumn([
                'buyer_gstin',
                'billing_address',
                'place_of_supply_state',
            ]);
        });
    }
};
