<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('cashfree_payment_id', 100)->nullable()->after('transaction_id');
            $table->decimal('payment_amount', 10, 2)->nullable()->after('cashfree_payment_id');
            $table->string('payment_method', 100)->nullable()->after('payment_amount');
            $table->timestamp('payment_date')->nullable()->after('payment_method');

            $table->unique('cashfree_payment_id');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_date']);
            $table->dropUnique(['cashfree_payment_id']);
            $table->dropColumn([
                'cashfree_payment_id',
                'payment_amount',
                'payment_method',
                'payment_date',
            ]);
        });
    }
};
