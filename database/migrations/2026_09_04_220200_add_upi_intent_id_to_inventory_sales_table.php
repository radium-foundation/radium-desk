<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->foreignId('upi_intent_id')
                ->nullable()
                ->after('payment_reference')
                ->constrained('pos_payment_intents')
                ->nullOnDelete();
            $table->unique('upi_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->dropForeign(['upi_intent_id']);
            $table->dropUnique(['upi_intent_id']);
            $table->dropColumn('upi_intent_id');
        });
    }
};
