<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('bank_reference', 100)->nullable()->after('payment_date');
            $table->string('gateway_order_id', 100)->nullable()->after('bank_reference');
            $table->string('gateway_payment_id', 100)->nullable()->after('gateway_order_id');

            $table->index('bank_reference');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['bank_reference']);
            $table->dropColumn([
                'bank_reference',
                'gateway_order_id',
                'gateway_payment_id',
            ]);
        });
    }
};
