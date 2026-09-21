<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table): void {
            $table->decimal('shipping_amount', 12, 2)->default(0)->after('tax');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table): void {
            $table->dropColumn('shipping_amount');
        });
    }
};
