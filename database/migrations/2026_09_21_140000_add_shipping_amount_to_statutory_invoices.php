<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_invoices', function (Blueprint $table) {
            $table->decimal('shipping_amount', 12, 2)
                ->default(0)
                ->after('taxable_value');
        });
    }

    public function down(): void
    {
        Schema::table('statutory_invoices', function (Blueprint $table) {
            $table->dropColumn('shipping_amount');
        });
    }
};
