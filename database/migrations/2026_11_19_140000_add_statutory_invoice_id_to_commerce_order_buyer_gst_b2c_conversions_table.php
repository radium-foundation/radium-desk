<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_order_buyer_gst_b2c_conversions', function (Blueprint $table) {
            $table->foreignId('statutory_invoice_id')->nullable()->after('commerce_order_id');
            $table->foreign('statutory_invoice_id', 'co_b2c_conv_invoice_fk')
                ->references('id')->on('statutory_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_order_buyer_gst_b2c_conversions', function (Blueprint $table) {
            $table->dropForeign('co_b2c_conv_invoice_fk');
            $table->dropColumn('statutory_invoice_id');
        });
    }
};
