<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_products', 'sell_on_radiumbox')) {
                $table->boolean('sell_on_radiumbox')->default(true)->after('is_active');
            }
            if (! Schema::hasColumn('inventory_products', 'rd_service_available')) {
                $table->boolean('rd_service_available')->default(true)->after('sell_on_radiumbox');
            }
            if (! Schema::hasColumn('inventory_products', 'amc_available')) {
                $table->boolean('amc_available')->default(true)->after('rd_service_available');
            }
        });

        if (Schema::hasTable('inventory_products') && Schema::hasColumn('inventory_products', 'rd_service_available')) {
            DB::table('inventory_products')
                ->where('sku', 'RBMFS100L0')
                ->update(['rd_service_available' => false]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            foreach (['sell_on_radiumbox', 'rd_service_available', 'amc_available'] as $column) {
                if (Schema::hasColumn('inventory_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
