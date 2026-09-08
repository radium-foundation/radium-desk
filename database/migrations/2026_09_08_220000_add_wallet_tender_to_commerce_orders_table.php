<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commerce_orders', 'wallet_tender_amount')) {
            Schema::table('commerce_orders', function (Blueprint $table) {
                $table->decimal('wallet_tender_amount', 12, 2)->nullable()->after('order_value');
                $table->string('wallet_tender_reference', 128)->nullable()->after('wallet_tender_amount');
            });
        }
    }

    public function down(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            if (Schema::hasColumn('commerce_orders', 'wallet_tender_reference')) {
                $table->dropColumn('wallet_tender_reference');
            }
            if (Schema::hasColumn('commerce_orders', 'wallet_tender_amount')) {
                $table->dropColumn('wallet_tender_amount');
            }
        });
    }
};
