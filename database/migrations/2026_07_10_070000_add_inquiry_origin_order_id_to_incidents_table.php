<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('inquiry_origin_order_id')
                ->nullable()
                ->after('order_id')
                ->constrained('orders')
                ->nullOnDelete();

            $table->index('inquiry_origin_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inquiry_origin_order_id');
        });
    }
};
