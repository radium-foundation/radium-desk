<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table): void {
            $table->string('buyer_name', 160)->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table): void {
            $table->dropColumn('buyer_name');
        });
    }
};
